import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:saber/data/editor/editor_core_info.dart';
import 'package:saber/data/file_manager/file_manager.dart';
import 'package:saber/data/flavor_config.dart';
import 'package:saber/data/yc_note/account.dart';

import '../utils/test_mock_channel_handlers.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  setupMockPathProvider();
  FlavorConfig.setup();
  late Directory root;
  Map<String, dynamic> session(bool write) => {
    'user_id': 1,
    'username': 'fixture',
    'yc_role': 'user',
    'status': 'approved',
    'can_write': write,
    'verified_at': DateTime.now().millisecondsSinceEpoch ~/ 1000,
    'expires_at': DateTime.now().millisecondsSinceEpoch ~/ 1000 + 3600,
    'offline_lease_seconds': 3600,
  };
  setUp(() async {
    root = Directory.systemTemp.createTempSync('yc-note-read-only-');
    await FileManager.init(
      documentsDirectory: root.path,
      shouldWatchRootDirectory: false,
    );
    await File('${root.path}/existing.sbn2').writeAsString('original notebook');
    ycAccount.setSessionForTesting(session(false));
  });
  tearDown(() async {
    ycAccount.setSessionForTesting({});
    await root.delete(recursive: true);
  });
  test('permission removal keeps reading available', () async {
    expect(ycAccount.canUse, isTrue);
    expect(ycAccount.canWrite, isFalse);
    expect(await FileManager.readFile('/existing.sbn2'), isNotEmpty);
  });
  test('write and delete attempts leave notebook unchanged', () async {
    await FileManager.writeFile('/existing.sbn2', [1, 2, 3], awaitWrite: true);
    await FileManager.deleteFile('/existing.sbn2');
    expect(
      await File('${root.path}/existing.sbn2').readAsString(),
      'original notebook',
    );
  });
  test('move and folder creation are disabled', () async {
    final path = await FileManager.moveFile('/existing.sbn2', '/moved.sbn2');
    await FileManager.createFolder('/blocked');
    expect(path, '/existing.sbn2');
    expect(File('${root.path}/moved.sbn2').existsSync(), isFalse);
    expect(Directory('${root.path}/blocked').existsSync(), isFalse);
  });
  test('editor follows permission changes without reloading notebook', () {
    final core = EditorCoreInfo(filePath: '/existing');
    expect(core.readOnly, isTrue);
    ycAccount.setSessionForTesting(session(true));
    expect(core.readOnly, isFalse);
    ycAccount.setSessionForTesting(session(false));
    expect(core.readOnly, isTrue);
  });
}
