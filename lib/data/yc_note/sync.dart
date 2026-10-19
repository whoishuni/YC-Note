import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:math';

import 'package:crypto/crypto.dart';
import 'package:flutter/foundation.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';
import 'package:saber/data/file_manager/file_manager.dart';
import 'package:saber/data/yc_note/account.dart';
import 'package:saber/data/yc_note/file_coordinator.dart';
import 'package:saber/data/yc_note/local_batch.dart';
import 'package:saber/data/yc_note/pdf_exports.dart';
import 'package:saber/data/yc_note/sync_plan.dart';

final ycSync = YcSync();

class YcSync extends ChangeNotifier {
  new({
    YcApi Function(String)? apiFactory,
    Future<void> Function()? refreshAccount,
    Future<Directory> Function()? supportDirectory,
    this.preparePdfs,
  }) : _apiFactory = apiFactory ?? ((token) => YcApi(token: token)),
       _refreshAccount = refreshAccount ?? ycAccount.refresh,
       _supportDirectory = supportDirectory ?? getApplicationSupportDirectory;

  final Future<bool> Function()? preparePdfs;
  final YcApi Function(String) _apiFactory;
  final Future<void> Function() _refreshAccount;
  final Future<Directory> Function() _supportDirectory;
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
    ycAccount.beforeLogout = () async => await _running;
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

  static File _file(String root, String path) =>
      File(p.join(root, path.substring(1)));
  static Future<String?> _hash(File file) async =>
      file.existsSync() ? YcLocalBatch.digest(file) : null;
  static String? _remoteHash(Map<String, dynamic>? f) =>
      f == null || f['deleted'] == 1 ? null : f['blob_hash'] as String;
  static int _revision(Map<String, dynamic>? f) =>
      (f?['revision'] as num?)?.toInt() ?? 0;
  static Map<String, dynamic> _baseline(Map<String, dynamic> f) => {
    'revision': _revision(f),
    'hash': _remoteHash(f),
  };
  static String _group(String path) =>
      RegExp(r'^(.+\.sbn2?)(?:\.(?:[0-9]+|p|pdf|pdf\.json))?$')
          .firstMatch(path)
          ?.group(1) ??
      path;
  static String _id() => List.generate(
    32,
    (_) => Random.secure().nextInt(256).toRadixString(16).padLeft(2, '0'),
  ).join();

  static Future<Map<String, dynamic>> _readJson(File file) async =>
      file.existsSync()
      ? Map<String, dynamic>.from(jsonDecode(await file.readAsString()) as Map)
      : {};

  static Future<void> _writeJson(File file, Object data) async {
    final temp = File('${file.path}.next');
    await temp.parent.create(recursive: true);
    await temp.writeAsString(jsonEncode(data), flush: true);
    await temp.rename(file.path);
  }

  Future<void> _sync() async {
    YcApi? api;
    Directory? workspace;
    final root = FileManager.documentsDirectory;
    final namespace = ycAccount.namespace;
    bool sameAccount() =>
        ycAccount.canUse &&
        ycAccount.namespace == namespace &&
        FileManager.documentsDirectory == root;
    void requireAccount() {
      if (!sameAccount()) throw StateError('Account changed during sync');
    }

    try {
      message = '同步中…';
      notifyListeners();
      await _refreshAccount();
      if (!sameAccount()) return;
      // Refresh may have renewed credentials. Never capture the old token.
      api = _apiFactory(ycAccount.token);
      final batch = YcLocalBatch(root);
      final stateFile = _file(root, YcLocalBatch.statePath);
      final pendingFile = File(p.join(root, '.yc-sync-pending.json'));
      await batch.recover();
      final pdfsReady =
          await (preparePdfs?.call() ??
              YcPdfExports.ensureAll(canContinue: () => !editing));
      requireAccount();
      if (!stateFile.existsSync()) {
        final support = await _supportDirectory();
        final old = File(p.join(support.path, 'YC Note/sync/$namespace.json'));
        if (old.existsSync()) await _writeJson(stateFile, await _readJson(old));
      }
      final base = await _readJson(stateFile);
      workspace = await Directory(root).createTemp('.yc-sync-');
      final working = workspace;
      Future<void> saveBase() async {
        requireAccount();
        final source = File(p.join(working.path, 'state'));
        await _writeJson(source, base);
        await batch.apply({YcLocalBatch.statePath: source});
      }

      Future<void> sendPending(Map<String, dynamic> pending) async {
        requireAccount();
        if (!ycAccount.canWrite) throw const YcApiException(403, '目前為唯讀模式。');
        Map<String, dynamic> result;
        try {
          result = await api!.json('POST', 'commit', data: pending);
        } on YcApiException catch (e) {
          // A rejected compare-and-swap has no writes. Retain the exact request
          // for every other failure, including a lost response after commit.
          if (e.status == 409 && pendingFile.existsSync()) {
            await pendingFile.delete();
          }
          rethrow;
        }
        final changes = pending['changes'] as List;
        final files = result['files'] as List;
        if (changes.length != files.length) {
          throw const FormatException('Incomplete commit receipt');
        }
        final received = <String, Map<String, dynamic>>{
          for (final f in files)
            (f as Map)['path'] as String: Map<String, dynamic>.from(f),
        };
        for (final raw in changes) {
          final change = raw as Map;
          final f = received[change['path']];
          if (f == null ||
              _revision(f) != (change['base_revision'] as int) + 1 ||
              _remoteHash(f) != change['blob_hash'] ||
              (f['deleted'] == 1) != change['deleted']) {
            throw const FormatException('Invalid commit receipt');
          }
        }
        requireAccount();
        for (final f in received.values) {
          base[f['path'] as String] = _baseline(f);
        }
        await saveBase();
        if (pendingFile.existsSync()) await pendingFile.delete();
      }

      if (pendingFile.existsSync())
        await sendPending(await _readJson(pendingFile));
      requireAccount();
      final response = await api.json('GET', 'manifest');
      if (!(response['capabilities'] as List? ?? []).contains(
            'atomic_sync_v1',
          ) ||
          response['snapshot'] is! String ||
          !RegExp(r'^[a-f0-9]{64}$').hasMatch(response['snapshot'] as String)) {
        message = '伺服器需要更新，筆記已保留在本機。';
        return;
      }
      final remote = <String, Map<String, dynamic>>{};
      for (final value in response['files'] as List) {
        final f = Map<String, dynamic>.from(value as Map);
        final path = f['path'];
        if (path is! String ||
            !YcLocalBatch.validPath(path) ||
            remote.containsKey(path) ||
            f['revision'] is! num ||
            _revision(f) < 1 ||
            f['size'] is! num ||
            (f['size'] as num) < 0 ||
            ![0, 1].contains(f['deleted']) ||
            (f['deleted'] == 0 &&
                (f['blob_hash'] is! String ||
                    !RegExp(r'^[a-f0-9]{64}$')
                        .hasMatch(f['blob_hash'] as String)))) {
          throw const FormatException('Invalid remote manifest');
        }
        remote[path] = f;
      }
      final local = <String, String>{};
      final actions = <String, YcSyncAction>{};
      final uploads = <String, File>{};
      final localGroups = <String>{};
      final remoteGroups = <String>{};
      var skippedReadOnlyEdits = false;
      var backupCreated = false;
      await YcFileCoordinator.run(() async {
        requireAccount();
        await for (final entity in Directory(
          root,
        ).list(recursive: true, followLinks: false)) {
          if (entity is! File) continue;
          final relative =
              '/${p.relative(entity.path, from: root).replaceAll(p.separator, '/')}';
          if (!YcLocalBatch.validPath(relative) ||
              relative.startsWith('/同步衝突備份/'))
            continue;
          local[relative] = (await _hash(entity))!;
        }
        final paths = {...base.keys, ...local.keys, ...remote.keys}.toList()
          ..sort();
        final conflicts = <String>{};
        for (final path in paths) {
          if (!YcLocalBatch.validPath(path))
            throw const FormatException('Invalid synchronization baseline');
          final previous = base[path] as Map?;
          final r = remote[path];
          if (_revision(
                    previous == null
                        ? null
                        : Map<String, dynamic>.from(previous),
                  ) >
                  0 &&
              r == null) {
            throw StateError('Missing remote history');
          }
          actions[path] = planYcSync(
            localHash: local[path],
            remoteHash: _remoteHash(r),
            remoteRevision: _revision(r),
            baseHash: previous?['hash'] as String?,
            baseRevision: (previous?['revision'] as num?)?.toInt() ?? 0,
            hasBase: previous != null,
          );
          final group = _group(path);
          if (local[path] != _remoteHash(r) && local[path] != previous?['hash'])
            localGroups.add(group);
          final remoteChanged = previous == null
              ? _remoteHash(r) != null
              : _revision(r) != (previous['revision'] as num?)?.toInt();
          if (local[path] != _remoteHash(r) && remoteChanged)
            remoteGroups.add(group);
          if (actions[path] == YcSyncAction.conflict)
            conflicts.add(_group(path));
        }
        conflicts.addAll(localGroups.intersection(remoteGroups));
        for (final path in paths) {
          if (conflicts.contains(_group(path))) {
            actions[path] = local[path] == _remoteHash(remote[path])
                ? YcSyncAction.none
                : _remoteHash(remote[path]) == null
                ? YcSyncAction.deleteLocal
                : YcSyncAction.download;
          }
          if (!ycAccount.canWrite &&
              [
                YcSyncAction.upload,
                YcSyncAction.deleteRemote,
              ].contains(actions[path])) {
            skippedReadOnlyEdits = true;
            actions.remove(path);
          }
        }
        if (editing && actions.values.any(_incoming)) return;
        for (final entry in actions.entries) {
          if (entry.value != YcSyncAction.upload) continue;
          final copy = await _file(
            root,
            entry.key,
          ).copy(p.join(working.path, 'upload-${uploads.length}'));
          if (await _hash(copy) != local[entry.key])
            throw StateError('Local snapshot changed');
          uploads[entry.key] = copy;
        }
      });
      if (editing && actions.values.any(_incoming)) {
        message = '請返回筆記列表完成同步。';
        return;
      }
      final outgoing = <Map<String, dynamic>>[];
      for (final entry in actions.entries) {
        requireAccount();
        if (entry.value == YcSyncAction.upload) {
          if (!ycAccount.canWrite) throw const YcApiException(403, '目前為唯讀模式。');
          final bytes = await uploads[entry.key]!.readAsBytes();
          await api.request(
            'PUT',
            'stage',
            query: {'path': entry.key},
            headers: {
              'X-YC-Blob-Hash': local[entry.key]!,
              'Content-Type': 'application/octet-stream',
            },
            body: bytes,
          );
          outgoing.add({
            'path': entry.key,
            'base_revision': _revision(remote[entry.key]),
            'blob_hash': local[entry.key],
            'size': bytes.length,
            'deleted': false,
          });
        } else if (entry.value == YcSyncAction.deleteRemote) {
          outgoing.add({
            'path': entry.key,
            'base_revision': _revision(remote[entry.key]),
            'blob_hash': null,
            'size': 0,
            'deleted': true,
          });
        }
      }
      if (outgoing.isNotEmpty) {
        requireAccount();
        final pending = <String, dynamic>{
          'commit_id': _id(),
          'snapshot': response['snapshot'],
          'changes': outgoing,
        };
        await _writeJson(pendingFile, pending);
        await sendPending(pending);
      }
      final incoming = <String, File?>{};
      for (final entry in actions.entries) {
        if (!_incoming(entry.value)) continue;
        requireAccount();
        if (entry.value == YcSyncAction.deleteLocal) {
          incoming[entry.key] = null;
          continue;
        }
        final download = await api.request(
          'GET',
          'download',
          query: {
            'path': entry.key,
            'revision': '${_revision(remote[entry.key])}',
          },
        );
        if (sha256.convert(download.bodyBytes).toString() !=
                _remoteHash(remote[entry.key]) ||
            download.bodyBytes.length !=
                (remote[entry.key]!['size'] as num).toInt()) {
          throw const FormatException('Download verification failed');
        }
        final source = File(
          p.join(working.path, 'download-${incoming.length}'),
        );
        await source.writeAsBytes(download.bodyBytes, flush: true);
        incoming[entry.key] = source;
      }
      final applied = await YcFileCoordinator.run(() async {
        requireAccount();
        if (incoming.isNotEmpty && editing) return false;
        if (incoming.isNotEmpty) {
          final affected = incoming.keys.map(_group).toSet();
          final current = <String, String>{};
          await for (final entity in Directory(
            root,
          ).list(recursive: true, followLinks: false)) {
            if (entity is! File) continue;
            final path =
                '/${p.relative(entity.path, from: root).replaceAll(p.separator, '/')}';
            if (YcLocalBatch.validPath(path) && affected.contains(_group(path)))
              current[path] = (await _hash(entity))!;
          }
          for (final path in {...local.keys, ...current.keys}) {
            if (affected.contains(_group(path)) && current[path] != local[path])
              return false;
          }
          if (actions.entries.any(
            (e) =>
                _incoming(e.value) &&
                localGroups.contains(_group(e.key)) &&
                remoteGroups.contains(_group(e.key)),
          )) {
            await _backup(root, local);
            backupCreated = true;
          }
        }
        final nextBase = Map<String, dynamic>.from(base);
        for (final entry in actions.entries) {
          if ([
            YcSyncAction.upload,
            YcSyncAction.deleteRemote,
          ].contains(entry.value))
            continue;
          final r = remote[entry.key];
          if (r == null) {
            nextBase.remove(entry.key);
          } else {
            nextBase[entry.key] = _baseline(r);
          }
        }
        final source = File(p.join(working.path, 'state'));
        await _writeJson(source, nextBase);
        await batch.apply({
          ...incoming,
          YcLocalBatch.statePath: source,
        }, onProgress: (_) => requireAccount());
        for (final path in incoming.keys) {
          FileManager.broadcastFileWrite(
            incoming[path] == null
                ? FileOperationType.delete
                : FileOperationType.write,
            path,
          );
        }
        return true;
      });
      if (!applied) {
        message = '筆記正在修改，稍後繼續同步。';
        return;
      }
      lastSuccess = DateTime.now();
      message = skippedReadOnlyEdits
          ? '唯讀，本機變更尚未上傳'
          : backupCreated
          ? '已同步；衝突副本已保留'
          : pdfsReady
          ? '已同步'
          : '已同步；PDF 待更新';
    } on YcApiException catch (e) {
      message = e.status == 409 ? '其他裝置已更新，稍後重新同步。' : e.message;
      if (e.status == 401 || e.status == 403) await _refreshAccount();
    } on SocketException {
      message = '目前離線，筆記已保留在本機。';
    } on TimeoutException {
      message = '連線逾時，筆記已保留在本機。';
    } catch (_) {
      message = '同步未完成，筆記已保留在本機。';
    } finally {
      if (workspace?.existsSync() ?? false)
        await workspace!.delete(recursive: true);
      api?.client.close();
      notifyListeners();
    }
  }

  static bool _incoming(YcSyncAction action) =>
      action == YcSyncAction.download || action == YcSyncAction.deleteLocal;

  Future<void> _backup(String root, Map<String, String> local) async {
    final directory = await Directory(p.join(root, '.yc-conflicts'))
        .create(recursive: true);
    final temporary = await directory.createTemp('pending-');
    try {
      for (final path in local.keys) {
        final target = _file(temporary.path, path);
        await target.parent.create(recursive: true);
        await _file(root, path).copy(target.path);
        final handle = await target.open(mode: FileMode.append);
        try {
          await handle.flush();
        } finally {
          await handle.close();
        }
      }
      await temporary.rename(p.join(directory.path, _id()));
    } catch (_) {
      await temporary.delete(recursive: true);
      rethrow;
    }
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
