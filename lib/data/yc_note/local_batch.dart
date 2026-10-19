import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:crypto/crypto.dart';
import 'package:path/path.dart' as p;
import 'package:saber/data/yc_note/file_coordinator.dart';

/// A durable batch of notebook files, assets and their synchronization baseline.
/// Every before/after image is retained until the whole batch is committed.
final class YcLocalBatch {
  new(this.root);

  final String root;
  static const statePath = '/.yc-sync-state.json';
  Directory get _journal => Directory(p.join(root, '.yc-local-batch'));

  static bool validPath(String path) =>
      path.startsWith('/') &&
      path.length <= 1000 &&
      !path.contains(RegExp(r'[\\\x00-\x1f\x7f]')) &&
      path
          .substring(1)
          .split('/')
          .every((s) => s.isNotEmpty && !s.startsWith('.')) &&
      !path.endsWith('.yc-download');

  File _target(String path) {
    if (path != statePath && !validPath(path)) {
      throw const FormatException('Unsafe batch path');
    }
    final target = File(p.join(root, path.substring(1)));
    var current = target.path;
    while (p.isWithin(root, current) || p.equals(root, current)) {
      if (FileSystemEntity.typeSync(current, followLinks: false) ==
          FileSystemEntityType.link) {
        throw const FileSystemException(
          'Links are not allowed in account data',
        );
      }
      if (p.equals(root, current)) break;
      current = p.dirname(current);
    }
    return target;
  }

  static Future<String> digest(File file) async =>
      (await sha256.bind(file.openRead()).first).toString();

  static Future<File> _copyDurable(File source, String path) async {
    final copy = await source.copy(path);
    final handle = await copy.open(mode: FileMode.append);
    try {
      await handle.flush();
    } finally {
      await handle.close();
    }
    return copy;
  }

  Future<void> recover() => YcFileCoordinator.run(_recover);

  Future<void> _recover() async {
    if (FileSystemEntity.typeSync(_journal.path, followLinks: false) ==
        FileSystemEntityType.link) {
      throw const FileSystemException('Unsafe recovery directory');
    }
    if (!_journal.existsSync()) return;
    final manifest = File(p.join(_journal.path, 'manifest.json'));
    if (!manifest.existsSync()) {
      // Publication cannot begin until a complete manifest has been flushed.
      await _journal.delete(recursive: true);
      return;
    }
    final data =
        jsonDecode(await manifest.readAsString()) as Map<String, dynamic>;
    if (data['version'] != 1 || data['entries'] is! List) {
      throw const FormatException('Invalid recovery journal');
    }
    if (data['phase'] == 'committed' || data['phase'] == 'rolled_back') {
      await _journal.delete(recursive: true);
      return;
    }
    if (data['phase'] != 'prepared') {
      throw const FormatException('Invalid recovery phase');
    }
    final entries = (data['entries'] as List)
        .map((v) => Map<String, dynamic>.from(v as Map))
        .toList();
    try {
      await _verify(entries, 'after');
    } on FileSystemException {
      await _verify(entries, 'before');
      await _publish(entries, 'before');
      await _mark(entries, 'rolled_back');
      await _journal.delete(recursive: true);
      return;
    }
    await _publish(entries, 'after');
    await _mark(entries, 'committed');
    await _journal.delete(recursive: true);
  }

  Future<void> _verify(List<Map<String, dynamic>> entries, String image) async {
    final seen = <String>{};
    for (var i = 0; i < entries.length; i++) {
      final entry = entries[i];
      final path = entry['path'];
      if (path is! String || !seen.add(path)) {
        throw const FormatException('Invalid recovery entry');
      }
      _target(path);
      final hash = entry[image];
      if (hash == null) continue;
      if (hash is! String || !RegExp(r'^[a-f0-9]{64}$').hasMatch(hash)) {
        throw const FormatException('Invalid recovery digest');
      }
      final file = File(p.join(_journal.path, image, '$i'));
      if (FileSystemEntity.typeSync(file.path, followLinks: false) !=
              FileSystemEntityType.file ||
          await digest(file) != hash) {
        throw const FileSystemException('Recovery image checksum mismatch');
      }
    }
  }

  Future<void> _mark(List<Map<String, dynamic>> entries, String phase) async {
    final temp = File(p.join(_journal.path, 'manifest.next'));
    await temp.writeAsString(
      jsonEncode({'version': 1, 'phase': phase, 'entries': entries}),
      flush: true,
    );
    await temp.rename(p.join(_journal.path, 'manifest.json'));
  }

  Future<void> _publish(
    List<Map<String, dynamic>> entries,
    String image, {
    FutureOr<void> Function(int)? onProgress,
  }) async {
    for (var i = 0; i < entries.length; i++) {
      final entry = entries[i];
      final target = _target(entry['path'] as String);
      if (entry[image] == null) {
        if (target.existsSync()) await target.delete();
      } else {
        await target.parent.create(recursive: true);
        final temporary = await target.parent.createTemp('.yc-publish-');
        final temp = File(p.join(temporary.path, 'value'));
        final source = File(p.join(_journal.path, image, '$i'));
        try {
          await _copyDurable(source, temp.path);
          await temp.rename(target.path);
        } finally {
          if (temporary.existsSync()) await temporary.delete(recursive: true);
        }
      }
      await onProgress?.call(i + 1);
    }
  }

  /// Null sources delete a path. Files are immutable inputs held by the caller.
  Future<void> apply(
    Map<String, File?> changes, {
    FutureOr<void> Function(int)? onProgress,
  }) => YcFileCoordinator.run(() async {
    await _recover();
    if (changes.isEmpty) return;
    await Directory(root).create(recursive: true);
    await Directory(p.join(_journal.path, 'before')).create(recursive: true);
    await Directory(p.join(_journal.path, 'after')).create(recursive: true);
    final entries = <Map<String, dynamic>>[];
    var prepared = false;
    try {
      for (final change in changes.entries) {
        final i = entries.length;
        final target = _target(change.key);
        String? before;
        String? after;
        if (target.existsSync()) {
          final copy = await _copyDurable(
            target,
            p.join(_journal.path, 'before', '$i'),
          );
          before = await digest(copy);
        }
        if (change.value case final File source) {
          final copy = await _copyDurable(
            source,
            p.join(_journal.path, 'after', '$i'),
          );
          after = await digest(copy);
        }
        entries.add({'path': change.key, 'before': before, 'after': after});
      }
      await _mark(entries, 'prepared');
      prepared = true;
      await _publish(entries, 'after', onProgress: onProgress);
      await _mark(entries, 'committed');
    } catch (_) {
      if (prepared) {
        await _verify(entries, 'before');
        await _publish(entries, 'before');
        await _mark(entries, 'rolled_back');
      }
      if (_journal.existsSync()) await _journal.delete(recursive: true);
      rethrow;
    }
    // A committed journal is safe to clean on next launch if cleanup is denied.
    try {
      await _journal.delete(recursive: true);
    } on FileSystemException {
      return;
    }
  });
}
