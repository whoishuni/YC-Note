import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:saber/data/yc_note/file_coordinator.dart';
import 'package:saber/data/yc_note/local_batch.dart';

void main() {
  late Directory root;
  late YcLocalBatch batch;
  Future<File> input(String name, String text) =>
      File('${root.path}/input-$name').writeAsString(text, flush: true);
  setUp(() async {
    root = await Directory.systemTemp.createTemp('yc-batch-test-');
    batch = YcLocalBatch(root.path);
    await File('${root.path}/note.sbn2').writeAsString('old main');
    await File('${root.path}/note.sbn2.0').writeAsString('old asset');
  });
  tearDown(() async => await root.delete(recursive: true));

  test(
    'failed second publish rolls back every file and its baseline',
    () async {
      await expectLater(
        batch.apply(
          {
            '/note.sbn2': await input('main', 'new main'),
            '/note.sbn2.0': await input('asset', 'new asset'),
            YcLocalBatch.statePath: await input('state', '{"revision":2}'),
          },
          onProgress: (n) {
            if (n == 2) throw const FileSystemException('injected failure');
          },
        ),
        throwsA(isA<FileSystemException>()),
      );
      expect(await File('${root.path}/note.sbn2').readAsString(), 'old main');
      expect(
        await File('${root.path}/note.sbn2.0').readAsString(),
        'old asset',
      );
      expect(File('${root.path}${YcLocalBatch.statePath}').existsSync(), false);
      expect(Directory('${root.path}/.yc-local-batch').existsSync(), false);
    },
  );

  Future<void> interrupt() async {
    await input('main', 'new main');
    await input('asset', 'new asset');
    await input('state', '{"revision":2}');
    final process = await Process.start('dart', [
      '--packages=.dart_tool/package_config.json',
      'test/yc_note/fixtures/crash_batch.dart',
      root.path,
    ]);
    final errors = process.stderr.transform(utf8.decoder).join();
    try {
      expect(
        await process.stdout
            .transform(utf8.decoder)
            .transform(const LineSplitter())
            .first
            .timeout(const Duration(seconds: 15)),
        'READY',
      );
      expect(process.kill(ProcessSignal.sigkill), true);
      await process.exitCode;
      expect(await errors, isEmpty);
    } finally {
      process.kill(ProcessSignal.sigkill);
    }
  }

  test(
    'actual process kill recovers main, assets and baseline together',
    () async {
      await interrupt();
      expect(await File('${root.path}/note.sbn2').readAsString(), 'new main');
      expect(
        await File('${root.path}/note.sbn2.0').readAsString(),
        'old asset',
      );
      await batch.recover();
      expect(
        await File('${root.path}/note.sbn2.0').readAsString(),
        'new asset',
      );
      expect(
        await File('${root.path}${YcLocalBatch.statePath}').readAsString(),
        '{"revision":2}',
      );
      await File('${root.path}/note.sbn2').writeAsString('later edit');
      await batch.recover();
      expect(await File('${root.path}/note.sbn2').readAsString(), 'later edit');
    },
  );

  test(
    'corrupt after image rolls back the complete interrupted batch',
    () async {
      await interrupt();
      await File('${root.path}/.yc-local-batch/after/1')
          .writeAsString('corrupt');
      await batch.recover();
      expect(await File('${root.path}/note.sbn2').readAsString(), 'old main');
      expect(
        await File('${root.path}/note.sbn2.0').readAsString(),
        'old asset',
      );
      expect(File('${root.path}${YcLocalBatch.statePath}').existsSync(), false);
    },
  );

  test('unrecoverable images fail closed and retain journal', () async {
    await interrupt();
    await File('${root.path}/.yc-local-batch/after/1').writeAsString('corrupt');
    await File('${root.path}/.yc-local-batch/before/1')
        .writeAsString('also corrupt');
    await expectLater(batch.recover(), throwsA(isA<FileSystemException>()));
    expect(Directory('${root.path}/.yc-local-batch').existsSync(), true);
  });

  test('invalid paths and symbolic links cannot escape account', () async {
    final source = await input('main', 'new');
    for (final path in [
      '/../outside',
      '/.secret',
      '/folder//file',
      '/bad\\file',
    ]) {
      await expectLater(batch.apply({path: source}), throwsFormatException);
    }
    final outside = await Directory.systemTemp.createTemp('yc-outside-');
    try {
      await Link('${root.path}/link').create(outside.path);
      await expectLater(
        batch.apply({'/link/file': source}),
        throwsA(isA<FileSystemException>()),
      );
      expect(outside.listSync(), isEmpty);
    } finally {
      await outside.delete();
    }
  });

  test(
    'a reader using the coordinator cannot see an incomplete batch',
    () async {
      final first = Completer<void>();
      final release = Completer<void>();
      final write = batch.apply(
        {
          '/note.sbn2': await input('main', 'new main'),
          '/note.sbn2.0': await input('asset', 'new asset'),
        },
        onProgress: (n) async {
          if (n == 1) {
            first.complete();
            await release.future;
          }
        },
      );
      await first.future;
      var entered = false;
      final read = YcFileCoordinator.run(() async {
        entered = true;
        return [
          await File('${root.path}/note.sbn2').readAsString(),
          await File('${root.path}/note.sbn2.0').readAsString(),
        ];
      });
      await Future<void>.delayed(Duration.zero);
      expect(entered, false);
      release.complete();
      await write;
      expect(await read, ['new main', 'new asset']);
    },
  );
}
