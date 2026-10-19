import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:crypto/crypto.dart';
import 'package:flutter/foundation.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';
import 'package:saber/data/file_manager/file_manager.dart';
import 'package:saber/data/yc_note/account.dart';
import 'package:saber/data/yc_note/sync_plan.dart';

final ycSync = YcSync();

class YcSync extends ChangeNotifier {
  var message = '尚未同步';
  var editing = false;
  bool get busy => _running != null;
  DateTime? lastSuccess;
  Future<void>? _running;
  Timer? _debounce;
  Timer? _periodic;
  StreamSubscription? _writes;

  void start() {
    _writes ??= FileManager.fileWriteStream.stream.listen((_) => schedule());
    _periodic ??= Timer.periodic(const Duration(minutes: 2), (_) => schedule());
    ycAccount.addListener(schedule);
    ycAccount.beforeLogout = () async {
      await _running;
    };
    schedule();
  }

  void schedule() {
    if (busy) return;
    _debounce?.cancel();
    if (!ycAccount.canUse) return;
    _debounce = Timer(const Duration(seconds: 2), () => unawaited(sync()));
  }

  Future<void> sync() {
    if (_running != null) return _running!;
    if (!ycAccount.canUse) return Future.value();
    final future = _sync();
    _running = future;
    notifyListeners();
    return future.whenComplete(() {
      _running = null;
      notifyListeners();
    });
  }

  Future<String?> _hash(File file) async => file.existsSync()
      ? sha256.convert(await file.readAsBytes()).toString()
      : null;

  Future<void> _sync() async {
    final api = YcApi(token: ycAccount.token);
    final root = FileManager.documentsDirectory;
    final namespace = ycAccount.namespace;
    try {
      message = '同步中…';
      notifyListeners();
      await ycAccount.refresh();
      if (!ycAccount.canUse) return;
      final support = await getApplicationSupportDirectory();
      final stateFile = File('${support.path}/YC Note/sync/$namespace.json');
      final base = stateFile.existsSync()
          ? jsonDecode(await stateFile.readAsString()) as Map<String, dynamic>
          : <String, dynamic>{};
      final response = await api.json('GET', 'manifest');
      final remote = <String, Map<String, dynamic>>{};
      for (final value in response['files'] as List) {
        final f = Map<String, dynamic>.from(value as Map);
        final path = f['path'] as String;
        if (!_validPath(path))
          throw const FormatException('Unsafe remote file path');
        remote[path] = f;
      }
      final local = <String, String>{};
      await for (final f in Directory(
        root,
      ).list(recursive: true, followLinks: false)) {
        if (f is! File) continue;
        final relative =
            '/${p.relative(f.path, from: root).replaceAll(p.separator, '/')}';
        if (!_validPath(relative)) continue;
        local[relative] = (await _hash(f))!;
      }
      Future<void> saveState() async {
        await stateFile.parent.create(recursive: true);
        final temp = File('${stateFile.path}.tmp');
        await temp.writeAsString(jsonEncode(base), flush: true);
        await temp.rename(stateFile.path);
      }

      final paths = {...base.keys, ...local.keys, ...remote.keys}.toList()
        ..sort((a, b) {
          // Attachments first; notebook files become visible after their assets.
          final am = a.endsWith('.sbn2') || a.endsWith('.sbn');
          final bm = b.endsWith('.sbn2') || b.endsWith('.sbn');
          return am == bm
              ? a.compareTo(b)
              : am
              ? 1
              : -1;
        });
      String? backupPath;
      var skippedReadOnlyEdits = false;
      // Take the conflict snapshot before ANY remote assets are applied.
      for (final path in paths) {
        final previous = base[path] as Map<String, dynamic>?;
        final r = remote[path];
        final action = planYcSync(
          localHash: local[path],
          remoteHash: r == null || r['deleted'] == 1
              ? null
              : r['blob_hash'] as String?,
          remoteRevision: (r?['revision'] as num?)?.toInt() ?? 0,
          baseHash: previous?['hash'] as String?,
          baseRevision: (previous?['revision'] as num?)?.toInt() ?? 0,
          hasBase: previous != null,
        );
        if (action == YcSyncAction.conflict) {
          if (editing) {
            message = '本機已保留；請返回筆記列表完成同步。';
            return;
          }
          backupPath = await _backup(root);
          break;
        }
      }
      for (final path in paths) {
        if (!ycAccount.canUse || ycAccount.namespace != namespace) return;
        final previous = base[path] as Map<String, dynamic>?;
        final r = remote[path];
        if (previous != null && r == null)
          throw StateError('伺服器缺少既有檔案記錄，已停止同步以保護本機筆記。');
        final remoteRevision = (r?['revision'] as num?)?.toInt() ?? 0;
        final remoteHash = r == null || r['deleted'] == 1
            ? null
            : r['blob_hash'] as String?;
        final file = File(p.join(root, path.substring(1)));
        var action = planYcSync(
          localHash: local[path],
          remoteHash: remoteHash,
          remoteRevision: remoteRevision,
          baseHash: previous?['hash'] as String?,
          baseRevision: (previous?['revision'] as num?)?.toInt() ?? 0,
          hasBase: previous != null,
        );
        if (!ycAccount.canWrite &&
            (action == YcSyncAction.upload ||
                action == YcSyncAction.deleteRemote)) {
          // Keep earlier offline edits locally; viewing permissions never upload.
          skippedReadOnlyEdits = true;
          continue;
        }
        if (editing &&
            {
              YcSyncAction.conflict,
              YcSyncAction.download,
              YcSyncAction.deleteLocal,
            }.contains(action)) {
          message = '本機已保留；請返回筆記列表完成同步。';
          return;
        }
        if (action == YcSyncAction.conflict) {
          // Preserve the ENTIRE notebook directory, including numbered assets.
          // A copied .sbn2 file alone would lose its PDF/image attachments.
          action = remoteHash == null
              ? YcSyncAction.deleteLocal
              : YcSyncAction.download;
        }
        switch (action) {
          case YcSyncAction.upload:
            final bytes = await file.readAsBytes();
            final uploadedHash = sha256.convert(bytes).toString();
            final result = jsonDecode(
              (await api.request(
                'PUT',
                'upload',
                query: {'path': path},
                headers: {
                  'X-YC-Base-Revision': '$remoteRevision',
                  'Content-Type': 'application/octet-stream',
                },
                body: bytes,
              )).body,
            ) as Map<String, dynamic>;
            base[path] = {'revision': result['revision'], 'hash': uploadedHash};
          case YcSyncAction.deleteRemote:
            final result = jsonDecode(
              (await api.request(
                'DELETE',
                'delete',
                query: {'path': path},
                headers: {'X-YC-Base-Revision': '$remoteRevision'},
              )).body,
            ) as Map<String, dynamic>;
            base[path] = {'revision': result['revision'], 'hash': null};
          case YcSyncAction.download:
            final download = await api.request(
              'GET',
              'download',
              query: {'path': path, 'revision': '$remoteRevision'},
            );
            if (sha256.convert(download.bodyBytes).toString() != remoteHash)
              throw const FormatException('下載內容驗證失敗。');
            // Never replace a file edited while a network request was in flight.
            if (editing || await _hash(file) != local[path]) {
              message = '筆記正在修改，稍後繼續同步。';
              return;
            }
            await file.parent.create(recursive: true);
            final temp = File('${file.path}.yc-download');
            await temp.writeAsBytes(download.bodyBytes, flush: true);
            await temp.rename(file.path);
            base[path] = {'revision': remoteRevision, 'hash': remoteHash};
            FileManager.broadcastFileWrite(FileOperationType.write, path);
          case YcSyncAction.deleteLocal:
            if (editing || await _hash(file) != local[path]) {
              message = '筆記正在修改，稍後繼續同步。';
              return;
            }
            if (file.existsSync()) await file.delete();
            base[path] = {'revision': remoteRevision, 'hash': null};
            FileManager.broadcastFileWrite(FileOperationType.delete, path);
          case YcSyncAction.none:
            base[path] = {'revision': remoteRevision, 'hash': remoteHash};
          case YcSyncAction.conflict:
            throw StateError('Unresolved conflict');
        }
        await saveState();
      }
      lastSuccess = DateTime.now();
      message = skippedReadOnlyEdits
          ? '唯讀，本機變更尚未上傳'
          : backupPath == null
          ? '已同步'
          : '衝突副本：$backupPath';
    } on YcApiException catch (e) {
      message = e.status == 409 ? '其他裝置剛更新了筆記，下次同步會保留衝突副本。' : e.message;
      if (e.status == 401 || e.status == 403) await ycAccount.refresh();
    } on SocketException {
      message = '目前離線，筆記已保留在本機。';
    } on TimeoutException {
      message = '連線逾時，筆記已保留在本機。';
    } catch (e) {
      message = '同步未完成：$e';
    } finally {
      api.client.close();
      notifyListeners();
    }
  }

  static bool _validPath(String path) {
    if (!path.startsWith('/') ||
        path.contains('\\') ||
        path.contains(RegExp(r'[\x00-\x1f\x7f]')))
      return false;
    return path
            .substring(1)
            .split('/')
            .every((s) => s.isNotEmpty && !s.startsWith('.')) &&
        !path.endsWith('.yc-download');
  }

  Future<String> _backup(String root) async {
    final name =
        '同步衝突備份/${DateTime.now().toUtc().toIso8601String().replaceAll(':', '-')}';
    final sources = await Directory(root)
        .list(recursive: true, followLinks: false)
        .where(
          (e) =>
              e is File &&
              !p
                  .relative(e.path, from: root)
                  .startsWith('同步衝突備份${p.separator}'),
        )
        .cast<File>()
        .toList();
    for (final file in sources) {
      final relative = p.relative(file.path, from: root);
      final target = File(p.join(root, name, relative));
      await target.parent.create(recursive: true);
      await file.copy(target.path);
    }
    return name;
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _periodic?.cancel();
    _writes?.cancel();
    ycAccount.removeListener(schedule);
    super.dispose();
  }
}
