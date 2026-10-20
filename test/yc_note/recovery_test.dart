import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:saber/components/canvas/save_indicator.dart';
import 'package:saber/data/editor/editor_core_info.dart';
import 'package:saber/data/editor/editor_history.dart';
import 'package:saber/data/editor/page.dart';
import 'package:saber/data/file_manager/file_manager.dart';
import 'package:saber/data/flavor_config.dart';
import 'package:saber/data/yc_note/account.dart';
import 'package:saber/data/yc_note/recovery.dart';
import 'package:saber/pages/editor/editor.dart';

import '../utils/test_mock_channel_handlers.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  setupMockPathProvider();
  FlavorConfig.setup();
  late Directory root;
  setUp(() async {
    root = Directory.systemTemp.createTempSync('yc-note-recovery-');
    await FileManager.init(
      documentsDirectory: root.path,
      shouldWatchRootDirectory: false,
    );
  });
  tearDown(() async {
    ycAccount.setSessionForTesting({});
    await root.delete(recursive: true);
  });

  test(
    'recovery retains every asset and leaves source files unchanged',
    () async {
      final original = File('${root.path}/筆記.sbn2');
      await original.writeAsString('previously saved');
      final notebook = Uint8List.fromList([12, 20, 26]);
      final path = await preserveYcNotebook(
        root: root.path,
        sourcePath: '/課堂/筆記.sbn2',
        notebook: notebook,
        assets: [
          Future.value([0, 1, 2]),
          Future.value([3, 4, 5]),
        ],
      );
      expect(await File('${root.path}$path').readAsBytes(), notebook);
      expect(await File('${root.path}$path.0').readAsBytes(), [0, 1, 2]);
      expect(await File('${root.path}$path.1').readAsBytes(), [3, 4, 5]);
      expect(await original.readAsString(), 'previously saved');
      expect(path, startsWith('/本機保留/'));
      expect(
        root.listSync().where((e) => e.path.contains('.yc-recovery-')),
        isEmpty,
      );
    },
  );

  test('a failed attachment never publishes an incomplete copy', () async {
    await expectLater(
      preserveYcNotebook(
        root: root.path,
        sourcePath: '/筆記.sbn2',
        notebook: Uint8List.fromList([1]),
        assets: [
          Future.value([1]),
          Future.error(const FileSystemException('missing asset')),
        ],
      ),
      throwsA(isA<FileSystemException>()),
    );
    expect(root.listSync(), isEmpty);
  });

  test(
    'storage failure removes hidden staging and never changes source',
    () async {
      await File('${root.path}/本機保留').writeAsString('blocked directory');
      await expectLater(
        preserveYcNotebook(
          root: root.path,
          sourcePath: '/筆記.sbn2',
          notebook: Uint8List.fromList([1]),
          assets: [],
        ),
        throwsA(isA<FileSystemException>()),
      );
      expect(root.listSync().whereType<Directory>(), isEmpty);
      expect(
        await File('${root.path}/本機保留').readAsString(),
        'blocked directory',
      );
    },
  );

  test(
    'read-only save preserves pending editor content and releases back gate',
    () async {
      final canonical = File('${root.path}/pending.sbn2');
      await canonical.writeAsString('original notebook');
      final core = EditorCoreInfo(filePath: '/pending')
        ..pages.add(EditorPage(size: const Size(400, 600)));
      final editor = EditorState()..coreInfo = core;
      editor.history.recordChange(
        EditorHistoryItem(
          type: .insertPage,
          pageIndex: 0,
          page: core.pages.first,
          strokes: [],
          images: [],
        ),
      );
      editor.savingState.value = SavingState.waitingToSave;
      final now = DateTime.now().millisecondsSinceEpoch ~/ 1000;
      ycAccount.setSessionForTesting({
        'user_id': 1,
        'status': 'approved',
        'can_write': false,
        'verified_at': now,
        'expires_at': now + 3600,
        'offline_lease_seconds': 3600,
      });
      await editor.saveToFile();
      expect(editor.savingState.value, SavingState.saved);
      expect(
        editor.history.isCurrentStateSaved,
        isFalse,
        reason: 'Recovery must not pretend the canonical notebook was saved',
      );
      expect(await canonical.readAsString(), 'original notebook');
      final copies = root
          .listSync(recursive: true)
          .whereType<File>()
          .where(
            (f) => f.path.endsWith('/pending.sbn2') && f.path != canonical.path,
          )
          .toList();
      expect(copies, hasLength(1));
      final relative = copies.single.path.substring(root.path.length);
      final recovered = await EditorCoreInfo.loadFromFilePath(
        relative.substring(0, relative.length - Editor.extension.length),
      );
      expect(recovered.pages.first.size, const Size(400, 600));
      await editor.saveToFile();
      expect(
        root
            .listSync(recursive: true)
            .whereType<File>()
            .where((f) => f.path.endsWith('/pending.sbn2')),
        hasLength(2),
        reason: 'Retrying back must not create repeated copies',
      );
      recovered.dispose();
      core.dispose();
      editor.savingState.dispose();
    },
  );
}
