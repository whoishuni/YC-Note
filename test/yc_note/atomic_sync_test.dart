import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:crypto/crypto.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:saber/data/file_manager/file_manager.dart';
import 'package:saber/data/flavor_config.dart';
import 'package:saber/data/yc_note/account.dart';
import 'package:saber/data/yc_note/sync.dart';

import '../utils/test_mock_channel_handlers.dart';

// Requests go through the production YcApi and real PHP endpoints. Only the
// destination and disposable fixture credential are replaced by this transport.
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  setupMockPathProvider();
  FlavorConfig.setup();
  late Map<String, dynamic> fixture;
  late Directory work, root;
  late Process server;
  late Uri endpoint;
  late Map<String, String> environment;
  late YcSync sync;
  late http.Client transport;
  Future<http.Response?> Function(http.Request)? intercept;

  Future<void> control(String code) async {
    final result = await Process.run('php', [
      '-r',
      "require getenv('YC_SOURCE').'/server/src/bootstrap.php'; \$s=yc_service(); $code",
    ], environment: environment);
    expect(result.exitCode, 0, reason: '${result.stderr}');
  }

  Future<http.Response> forward(http.Request original) async {
    final request =
        http.Request(
            original.method,
            endpoint.replace(queryParameters: original.url.queryParameters),
          )
          ..headers.addAll(original.headers)
          ..headers['Authorization'] = 'Bearer ${fixture['token']}'
          ..bodyBytes = original.bodyBytes;
    return http.Response.fromStream(await transport.send(request));
  }

  Future<Map<String, dynamic>> manifest() async {
    final request = http.Request(
      'GET',
      endpoint.replace(queryParameters: {'action': 'manifest'}),
    );
    return jsonDecode((await forward(request)).body) as Map<String, dynamic>;
  }

  Future<void> remoteWrite(Map<String, String> changes) async {
    final before = await manifest();
    final heads = {
      for (final f in before['files'] as List) (f as Map)['path']: f,
    };
    final plan = <Map<String, dynamic>>[];
    for (final entry in changes.entries) {
      final bytes = utf8.encode(entry.value);
      final hash = sha256.convert(bytes).toString();
      final request =
          http.Request(
              'PUT',
              endpoint.replace(
                queryParameters: {'action': 'stage', 'path': entry.key},
              ),
            )
            ..headers['X-YC-Blob-Hash'] = hash
            ..bodyBytes = bytes;
      expect((await forward(request)).statusCode, 200);
      plan.add({
        'path': entry.key,
        'base_revision': heads[entry.key]?['revision'] ?? 0,
        'blob_hash': hash,
        'size': bytes.length,
        'deleted': false,
      });
    }
    final request =
        http.Request(
            'POST',
            endpoint.replace(queryParameters: {'action': 'commit'}),
          )
          ..headers['Content-Type'] = 'application/json'
          ..body = jsonEncode({
            'commit_id': sha256
                .convert(
                  utf8.encode('${DateTime.now().microsecondsSinceEpoch}'),
                )
                .toString(),
            'snapshot': before['snapshot'],
            'changes': plan,
          });
    final result = await forward(request);
    expect(result.statusCode, 200, reason: result.body);
  }

  void session({bool write = true}) {
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

  Future<void> local(String path, String text) async {
    final file = File('${root.path}$path');
    await file.parent.create(recursive: true);
    await file.writeAsString(text, flush: true);
  }

  Future<String> contents(String path) =>
      File('${root.path}$path').readAsString();

  setUpAll(() async {
    final result = await Process.run('php', [
      'server/tests/integration.php',
      '--fixture',
    ]);
    expect(result.exitCode, 0, reason: '${result.stderr}');
    fixture = jsonDecode(
      (result.stdout as String).trim().split('\n').last,
    ) as Map<String, dynamic>;
    work = Directory(fixture['directory'] as String);
    final socket = await ServerSocket.bind(InternetAddress.loopbackIPv4, 0);
    final port = socket.port;
    await socket.close();
    endpoint = Uri.parse('http://127.0.0.1:$port/api.php');
    environment = {
      'YC_NOTE_CONFIG': fixture['config'] as String,
      'YC_SOURCE': Directory.current.path,
    };
    server = await Process.start('php', [
      '-S',
      '127.0.0.1:$port',
      '-t',
      'server/public',
    ], environment: environment);
    unawaited(server.stdout.drain<void>());
    unawaited(server.stderr.drain<void>());
    HttpOverrides.global = null;
    transport = http.Client();
    for (var n = 0; n < 100; n++) {
      try {
        await transport.get(endpoint);
        break;
      } on SocketException {
        await Future<void>.delayed(const Duration(milliseconds: 30));
      } on http.ClientException {
        await Future<void>.delayed(const Duration(milliseconds: 30));
      }
    }
  });
  tearDownAll(() async {
    transport.close();
    server.kill();
    await server.exitCode;
    await work.delete(recursive: true);
  });
  setUp(() async {
    root = await Directory.systemTemp.createTemp('yc-sync-client-');
    await FileManager.init(
      documentsDirectory: root.path,
      shouldWatchRootDirectory: false,
    );
    await control(
      "foreach(['files','revisions','sync_staging','sync_commits','owners'] as \$t) \$s->db->exec('DELETE FROM '.\$t); \$s->db->exec('DELETE FROM access_rules WHERE user_id=1');",
    );
    session();
    intercept = null;
    sync = YcSync(
      refreshAccount: () async {},
      supportDirectory: () async => root,
      preparePdfs: () async => true,
      apiFactory: (token) => YcApi(
        token: token,
        client: MockClient(
          (request) async =>
              await intercept?.call(request) ?? await forward(request),
        ),
      ),
    );
  });
  tearDown(() async {
    sync.dispose();
    ycAccount.setSessionForTesting({});
    await root.delete(recursive: true);
  });

  test('main and all assets use one commit and persist a baseline', () async {
    await local('/note.sbn2', 'main-v1');
    await local('/note.sbn2.0', 'asset-v1');
    var commits = 0;
    intercept = (r) async {
      if (r.url.queryParameters['action'] == 'commit') commits++;
      expect(r.url.queryParameters['action'], isNot('upload'));
      return null;
    };
    await sync.sync();
    expect(sync.message, '已同步');
    expect(commits, 1);
    expect((await manifest())['files'], hasLength(2));
    expect(File('${root.path}/.yc-sync-state.json').existsSync(), true);
    await sync.sync();
    expect(commits, 1);
  });
  test('broken second transfer publishes neither main nor asset', () async {
    await local('/note.sbn2', 'main-v1');
    await local('/note.sbn2.0', 'asset-v1');
    var stages = 0;
    intercept = (r) async {
      if (r.url.queryParameters['action'] == 'stage' && ++stages == 2)
        throw const SocketException('fixture disconnect');
      return null;
    };
    await sync.sync();
    expect((await manifest())['files'], isEmpty);
    expect(await contents('/note.sbn2'), 'main-v1');
    intercept = null;
    await sync.sync();
    expect(sync.message, '已同步');
    expect((await manifest())['files'], hasLength(2));
  });
  test(
    'lost commit response retries exact receipt without extra revisions',
    () async {
      await local('/note.sbn2', 'main-v1');
      await local('/note.sbn2.0', 'asset-v1');
      String? id;
      intercept = (r) async {
        if (r.url.queryParameters['action'] == 'commit') {
          id = (jsonDecode(r.body) as Map)['commit_id'] as String;
          await forward(r);
          throw const SocketException('response lost');
        }
        return null;
      };
      await sync.sync();
      expect(File('${root.path}/.yc-sync-pending.json').existsSync(), true);
      expect((await manifest())['files'], hasLength(2));
      intercept = (r) async {
        if (r.url.queryParameters['action'] == 'commit')
          expect((jsonDecode(r.body) as Map)['commit_id'], id);
        return null;
      };
      await sync.sync();
      expect(sync.message, '已同步');
      expect(File('${root.path}/.yc-sync-pending.json').existsSync(), false);
      for (final f in (await manifest())['files'] as List)
        expect((f as Map)['revision'], 1);
    },
  );
  test(
    'corrupt second download leaves every local canonical file untouched',
    () async {
      await remoteWrite({
        '/note.sbn2': 'remote main',
        '/note.sbn2.0': 'remote asset',
      });
      var downloads = 0;
      intercept = (r) async {
        if (r.url.queryParameters['action'] == 'download' && ++downloads == 2)
          return http.Response('corrupt', 200);
        return null;
      };
      await sync.sync();
      expect(File('${root.path}/note.sbn2').existsSync(), false);
      expect(File('${root.path}/note.sbn2.0').existsSync(), false);
      expect(sync.lastSuccess, isNull);
      intercept = null;
      await sync.sync();
      expect(await contents('/note.sbn2'), 'remote main');
      expect(await contents('/note.sbn2.0'), 'remote asset');
    },
  );
  test(
    'notebook conflict never mixes local-only assets into remote generation',
    () async {
      await remoteWrite({'/note.sbn2': 'v1', '/note.sbn2.0': 'asset1'});
      await sync.sync();
      await local('/note.sbn2', 'local edit');
      await local('/note.sbn2.2', 'local-only asset');
      await remoteWrite({
        '/note.sbn2': 'remote edit',
        '/note.sbn2.0': 'remote asset',
        '/note.sbn2.1': 'remote-only asset',
      });
      await sync.sync();
      expect(sync.message, contains('衝突副本'));
      expect(await contents('/note.sbn2'), 'remote edit');
      expect(await contents('/note.sbn2.0'), 'remote asset');
      expect(await contents('/note.sbn2.1'), 'remote-only asset');
      expect(File('${root.path}/note.sbn2.2').existsSync(), false);
      final copies = Directory('${root.path}/.yc-conflicts')
          .listSync(recursive: true)
          .whereType<File>()
          .where((f) => f.path.endsWith('/note.sbn2.2'))
          .toList();
      expect(copies, hasLength(1));
      expect(await copies.single.readAsString(), 'local-only asset');
      expect(
        ((await manifest())['files'] as List).any(
          (f) => (f as Map)['path'] == '/note.sbn2.2',
        ),
        false,
      );
    },
  );
  test(
    'matching source without a baseline can publish a new derived export',
    () async {
      await remoteWrite({'/note.sbn2': 'matching main'});
      await local('/note.sbn2', 'matching main');
      await local('/note.sbn2.pdf', 'new export');
      await sync.sync();
      expect(sync.message, '已同步');
      expect(await contents('/note.sbn2.pdf'), 'new export');
      expect((await manifest())['files'], hasLength(2));
      expect(Directory('${root.path}/.yc-conflicts').existsSync(), false);
    },
  );
  test(
    'different-file changes within one notebook still conflict as a bundle',
    () async {
      await remoteWrite({'/note.sbn2': 'v1', '/note.sbn2.0': 'asset1'});
      await sync.sync();
      await local('/note.sbn2', 'local main only');
      await remoteWrite({'/note.sbn2.0': 'remote asset only'});
      await sync.sync();
      expect(await contents('/note.sbn2'), 'v1');
      expect(await contents('/note.sbn2.0'), 'remote asset only');
      final main = (await manifest())['files'] as List;
      expect(
        (main.firstWhere((f) => (f as Map)['path'] == '/note.sbn2')
            as Map)['revision'],
        1,
      );
      final copies = Directory('${root.path}/.yc-conflicts')
          .listSync(recursive: true)
          .whereType<File>()
          .where((f) => f.path.endsWith('/note.sbn2'));
      expect(await copies.single.readAsString(), 'local main only');
    },
  );
  test(
    'new local attachment during download defers the entire notebook',
    () async {
      await remoteWrite({
        '/note.sbn2': 'remote main',
        '/note.sbn2.0': 'remote asset',
      });
      intercept = (r) async {
        if (r.url.queryParameters['action'] == 'download')
          await local('/note.sbn2.7', 'new local asset');
        return null;
      };
      await sync.sync();
      expect(File('${root.path}/note.sbn2').existsSync(), false);
      expect(await contents('/note.sbn2.7'), 'new local asset');
      expect(sync.lastSuccess, isNull);
    },
  );
  test(
    'local edit during download prevents applying the entire incoming batch',
    () async {
      await remoteWrite({
        '/note.sbn2': 'remote main',
        '/note.sbn2.0': 'remote asset',
      });
      var changed = false;
      intercept = (r) async {
        if (r.url.queryParameters['action'] == 'download' && !changed) {
          changed = true;
          await local('/note.sbn2', 'late edit');
        }
        return null;
      };
      await sync.sync();
      expect(await contents('/note.sbn2'), 'late edit');
      expect(File('${root.path}/note.sbn2.0').existsSync(), false);
      expect(sync.lastSuccess, isNull);
    },
  );
  test(
    'permission downgrade after transfer blocks the whole publication',
    () async {
      await local('/note.sbn2', 'main-v1');
      await local('/note.sbn2.0', 'asset-v1');
      intercept = (r) async {
        if (r.url.queryParameters['action'] == 'commit') {
          await control('\$s->setAccess(1,1,false);');
          session(write: false);
        }
        return null;
      };
      await sync.sync();
      expect((await manifest())['files'], isEmpty);
      expect(await contents('/note.sbn2'), 'main-v1');
      expect(await contents('/note.sbn2.0'), 'asset-v1');
    },
  );
  test(
    'competing commit rejects stale upload without partial publication',
    () async {
      await local('/note.sbn2', 'local main');
      await local('/note.sbn2.0', 'local asset');
      var once = false;
      intercept = (r) async {
        if (r.url.queryParameters['action'] == 'commit' && !once) {
          once = true;
          await remoteWrite({'/other.sbn2': 'competing note'});
        }
        return null;
      };
      await sync.sync();
      final files = (await manifest())['files'] as List;
      expect(files, hasLength(1));
      expect((files.single as Map)['path'], '/other.sbn2');
      expect(File('${root.path}/.yc-sync-pending.json').existsSync(), false);
      expect(await contents('/note.sbn2'), 'local main');
      intercept = null;
      await sync.sync();
      expect(sync.message, '已同步');
      expect((await manifest())['files'], hasLength(3));
    },
  );
}
