import 'dart:convert';
import 'dart:io';

import 'package:crypto/crypto.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:image/image.dart' as im;
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:pdfrx/pdfrx.dart';
import 'package:saber/components/canvas/image/editor_image.dart';
import 'package:saber/components/canvas/pencil_shader.dart';
import 'package:saber/data/editor/editor_core_info.dart';
import 'package:saber/data/editor/page.dart';
import 'package:saber/data/file_manager/file_manager.dart';
import 'package:saber/data/flavor_config.dart';
import 'package:saber/data/tools/stroke_properties.dart';
import 'package:saber/data/yc_note/account.dart';
import 'package:saber/data/yc_note/pdf_exports.dart';

import '../utils/test_mock_channel_handlers.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  setupMockPathProvider();
  FlavorConfig.setup();
  EditorImage.shouldLoadOutImmediately = true;
  late Directory root;
  const note = '/images.sbn2';
  const fake = '%PDF-1.4\nfixture\n%%EOF';
  void session(bool write) {
    final now = DateTime.now().millisecondsSinceEpoch ~/ 1000;
    ycAccount.setSessionForTesting({
      'user_id': 1,
      'status': 'approved',
      'can_write': write,
      'verified_at': now,
      'expires_at': now + 3600,
      'offline_lease_seconds': 3600,
    });
  }

  setUp(() async {
    root = await Directory.systemTemp.createTemp('yc-pdf-test-');
    await FileManager.init(
      documentsDirectory: root.path,
      shouldWatchRootDirectory: false,
    );
    session(true);
    StrokeOptionsExtension.setDefaults();
    await File('test/sbn_examples/v19_separate_assets.sbn2')
        .copy('${root.path}$note');
    await File('test/sbn_examples/v19_separate_assets.sbn2.0')
        .copy('${root.path}$note.0');
  });
  tearDown(() async {
    ycAccount.setSessionForTesting({});
    await root.delete(recursive: true);
  });

  test(
    'PDF snapshot uses inline assets and stamps complete source hashes',
    () async {
      var renders = 0;
      expect(
        await YcPdfExports.ensureCurrent(
          note,
          render: (info) async {
            renders++;
            final (_, assets) = info.saveToBinary(currentPageIndex: null);
            expect(assets.length, 1);
            expect(assets.getFile(0), isNull);
            expect(
              await assets.getBytes(0),
              await File('${root.path}$note.0').readAsBytes(),
            );
            return utf8.encode(fake);
          },
        ),
        true,
      );
      final stamp = jsonDecode(
        await File('${root.path}$note.pdf.json').readAsString(),
      ) as Map;
      expect((stamp['source'] as Map).length, 2);
      expect(stamp['pdf_hash'], sha256.convert(utf8.encode(fake)).toString());
      expect(
        await YcPdfExports.ensureCurrent(
          note,
          render: (_) async {
            renders++;
            return utf8.encode(fake);
          },
        ),
        true,
      );
      expect(renders, 1);
    },
  );
  test(
    'asset changes during rendering never publish a falsely current PDF',
    () async {
      expect(
        await YcPdfExports.ensureCurrent(
          note,
          render: (_) async {
            await File('${root.path}$note.0')
                .writeAsString('changed after capture');
            return utf8.encode(fake);
          },
        ),
        false,
      );
      expect(File('${root.path}$note.pdf').existsSync(), false);
      expect(File('${root.path}$note.pdf.json').existsSync(), false);
    },
  );
  test('downgrade during render prevents publishing generated files', () async {
    expect(
      await YcPdfExports.ensureCurrent(
        note,
        render: (_) async {
          session(false);
          return utf8.encode(fake);
        },
      ),
      false,
    );
    expect(File('${root.path}$note.pdf').existsSync(), false);
  });
  test('read-only never generates or changes canonical PDF sidecars', () async {
    session(false);
    var rendered = false;
    expect(
      await YcPdfExports.ensureCurrent(
        note,
        render: (_) async {
          rendered = true;
          return utf8.encode(fake);
        },
      ),
      false,
    );
    expect(rendered, false);
  });
  test('missing numbered asset leaves export pending', () async {
    await File('${root.path}$note.0').delete();
    var rendered = false;
    expect(
      await YcPdfExports.ensureCurrent(
        note,
        render: (_) async {
          rendered = true;
          return utf8.encode(fake);
        },
      ),
      false,
    );
    expect(rendered, false);
    expect(File('${root.path}$note.pdf').existsSync(), false);
  });
  testWidgets('real export renders external image into a readable PDF', (
    tester,
  ) async {
    await tester.pumpWidget(const MaterialApp(home: SizedBox()));
    await tester.runAsync(() async {
      await PencilShader.init();
      expect(await YcPdfExports.ensureCurrent(note), true);
      final pdf = File('${root.path}$note.pdf');
      final info = await Process.run('pdfinfo', [pdf.path]);
      expect(info.exitCode, 0, reason: '${info.stderr}');
      expect(info.stdout, contains('Pages:'));
      final png = '${root.path}/render.png';
      final render = await Process.run('gs', [
        '-q',
        '-dBATCH',
        '-dNOPAUSE',
        '-sDEVICE=pngalpha',
        '-dFirstPage=1',
        '-dLastPage=1',
        '-r72',
        '-o',
        png,
        pdf.path,
      ]);
      expect(render.exitCode, 0, reason: '${render.stderr}');
      final bitmap = im.decodePng(await File(png).readAsBytes())!;
      var colored = 0;
      for (final pixel in bitmap)
        if (pixel.a > 0 && pixel.r < 150 && pixel.g < 150 && pixel.b < 150)
          colored++;
      expect(
        colored,
        greaterThan(1000),
        reason: 'Export must include the colored external image',
      );
      final evidence = Platform.environment['YC_PDF_EVIDENCE'];
      if (evidence != null) {
        await Directory(evidence).create(recursive: true);
        await File(png).copy('$evidence/pdf-fixture.png');
      }
    });
  });
  testWidgets('PDF and SVG page backgrounds survive real export', (
    tester,
  ) async {
    await tester.pumpWidget(const MaterialApp(home: SizedBox()));
    await tester.runAsync(() async {
      await pdfrxFlutterInitialize();
      final pdf = pw.Document()
        ..addPage(
          pw.Page(
            pageFormat: const PdfPageFormat(300, 400),
            build: (_) => pw.Container(color: PdfColors.pink),
          ),
        );
      final pdfBytes = await pdf.save();
      for (final kind in ['pdf', 'svg']) {
        const size = Size(300, 400);
        final core = EditorCoreInfo(filePath: '/$kind');
        final EditorImage background = kind == 'pdf'
            ? PdfEditorImage(
                id: 1,
                assetCache: core.assetCache,
                pdfBytes: pdfBytes,
                pdfFile: null,
                pdfPage: 0,
                pageIndex: 0,
                pageSize: size,
                naturalSize: size,
                dstRect: Offset.zero & size,
                onMoveImage: null,
                onDeleteImage: null,
                onMiscChange: null,
              )
            : SvgEditorImage(
                id: 1,
                assetCache: core.assetCache,
                svgString: '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="400"><rect width="300" height="400" fill="#00aaff"/></svg>',
                svgFile: null,
                pageIndex: 0,
                pageSize: size,
                naturalSize: size,
                dstRect: Offset.zero & size,
                srcRect: Offset.zero & size,
                onMoveImage: null,
                onDeleteImage: null,
                onMiscChange: null,
              );
        core.pages.add(EditorPage(size: size, backgroundImage: background));
        final (bson, assets) = core.saveToBinary(currentPageIndex: null);
        await FileManager.writeNotebook('/$kind.sbn2', bson, [
          for (var i = 0; i < assets.length; i++)
            (source: assets.getFile(i), readBytes: () => assets.getBytes(i)),
        ]);
        expect(
          await YcPdfExports.ensureCurrent('/$kind.sbn2'),
          true,
          reason: kind,
        );
        final output = '${root.path}/$kind.png';
        final rendered = await Process.run('gs', [
          '-q',
          '-dBATCH',
          '-dNOPAUSE',
          '-sDEVICE=pngalpha',
          '-dFirstPage=1',
          '-dLastPage=1',
          '-r72',
          '-o',
          output,
          '${root.path}/$kind.sbn2.pdf',
        ]);
        expect(rendered.exitCode, 0, reason: '${rendered.stderr}');
        final bitmap = im.decodePng(await File(output).readAsBytes())!;
        final center = bitmap.getPixel(bitmap.width ~/ 2, bitmap.height ~/ 2);
        if (kind == 'pdf') {
          expect(center.r, greaterThan(200));
          expect(center.g, lessThan(130));
        } else {
          expect(center.r, lessThan(40));
          expect(center.b, greaterThan(200));
        }
        core.dispose();
      }
    });
  });
}
