import 'dart:convert';
import 'dart:io';

import 'package:bson/bson.dart';
import 'package:crypto/crypto.dart';
import 'package:flutter/foundation.dart';
import 'package:logging/logging.dart';
import 'package:path/path.dart' as p;
import 'package:saber/data/editor/editor_core_info.dart';
import 'package:saber/data/editor/editor_exporter.dart';
import 'package:saber/data/file_manager/file_manager.dart';
import 'package:saber/data/yc_note/account.dart';
import 'package:saber/data/yc_note/file_coordinator.dart';
import 'package:saber/data/yc_note/local_batch.dart';

abstract final class YcPdfExports {
  static final _log = Logger('YcPdfExports');
  static final _notebook = RegExp(r'\.sbn2?$');

  static Future<Map<String, File>> _sources(String root, String path) async {
    final main = File(p.join(root, path.substring(1)));
    if (!main.existsSync()) return {};
    final sources = <String, File>{path: main};
    final assets = RegExp('^${RegExp.escape(p.basename(path))}\\.[0-9]+\$');
    await for (final entity in main.parent.list(followLinks: false)) {
      if (entity is File && assets.hasMatch(p.basename(entity.path))) {
        sources['${p.posix.dirname(path) == '/' ? '' : p.posix.dirname(path)}/${p.basename(entity.path)}'] =
            entity;
      }
    }
    return sources;
  }

  static Future<Map<String, String>> _hashes(Map<String, File> files) async => {
    for (final entry in files.entries)
      entry.key: await YcLocalBatch.digest(entry.value),
  };

  static Future<bool> ensureAll({required bool Function() canContinue}) async {
    if (!ycAccount.canWrite || !canContinue()) return true;
    final root = FileManager.documentsDirectory;
    final paths = <String>[];
    await YcFileCoordinator.run(() async {
      await for (final entity in Directory(
        root,
      ).list(recursive: true, followLinks: false)) {
        if (entity is! File) continue;
        final path =
            '/${p.relative(entity.path, from: root).replaceAll(p.separator, '/')}';
        if (YcLocalBatch.validPath(path) &&
            _notebook.hasMatch(path) &&
            !path.startsWith('/同步衝突備份/'))
          paths.add(path);
      }
    });
    var complete = true;
    for (final path in paths) {
      if (!canContinue() ||
          root != FileManager.documentsDirectory ||
          !ycAccount.canWrite)
        return false;
      if (!await ensureCurrent(path)) complete = false;
    }
    return complete;
  }

  /// Render an immutable, inline asset snapshot. Publish only if the entire
  /// canonical notebook still matches, including every external attachment.
  static Future<bool> ensureCurrent(
    String path, {
    Future<List<int>> Function(EditorCoreInfo)? render,
  }) async {
    if (!YcLocalBatch.validPath(path) || !_notebook.hasMatch(path))
      return false;
    final root = FileManager.documentsDirectory;
    final namespace = ycAccount.namespace;
    bool allowed() =>
        ycAccount.canWrite &&
        FileManager.canWriteAccount &&
        root == FileManager.documentsDirectory &&
        namespace == ycAccount.namespace;
    EditorCoreInfo? snapshot;
    Map<String, String>? sourceHashes;
    try {
      final fresh = await YcFileCoordinator.run(() async {
        if (!allowed()) return false;
        final sources = await _sources(root, path);
        if (sources.isEmpty) return false;
        sourceHashes = await _hashes(sources);
        final pdf = File(p.join(root, '${path.substring(1)}.pdf'));
        final stamp = File('${pdf.path}.json');
        if (pdf.existsSync() && stamp.existsSync()) {
          try {
            final metadata = jsonDecode(await stamp.readAsString()) as Map;
            if (metadata['v'] == 1 &&
                mapEquals(
                  Map<String, String>.from(metadata['source'] as Map),
                  sourceHashes,
                ) &&
                metadata['pdf_hash'] == await YcLocalBatch.digest(pdf))
              return true;
          } catch (_) {
            /* Regenerate an invalid export. */
          }
        }
        final bytes = await sources[path]!.readAsBytes();
        final dynamic decoded = path.endsWith('.sbn2')
            ? BsonCodec.deserialize(BsonBinary.from(bytes))
            : jsonDecode(utf8.decode(bytes));
        if (decoded is! Map)
          throw const FormatException('Unsupported notebook');
        final json = Map<String, dynamic>.from(decoded);
        if (sources.length > 1 || (json['v'] as int? ?? 0) >= 19) {
          final assets = <int, Uint8List>{};
          for (final entry in sources.entries) {
            if (entry.key == path) continue;
            final index = int.parse(entry.key.substring(path.length + 1));
            if (index > 10000)
              throw const FormatException('Invalid asset index');
            assets[index] = await entry.value.readAsBytes();
          }
          final max = assets.keys.isEmpty
              ? -1
              : assets.keys.reduce((a, b) => a > b ? a : b);
          json['a'] = List.generate(max + 1, (i) => assets[i] ?? Uint8List(0));
        }
        snapshot = EditorCoreInfo.fromJson(
          json,
          filePath: path.replaceFirst(_notebook, ''),
          onlyFirstPage: false,
        );
        if (snapshot!.readOnlyReason != null)
          throw const FormatException('Unsupported notebook version');
        return false;
      });
      if (fresh) return true;
      if (snapshot == null) return false;
      final bytes = await (render?.call(snapshot!) ?? _render(snapshot!));
      if (bytes.length < 5 || ascii.decode(bytes.take(5).toList()) != '%PDF-')
        throw const FormatException('Invalid PDF export');
      return await YcFileCoordinator.run(() async {
        if (!allowed() ||
            !mapEquals(await _hashes(await _sources(root, path)), sourceHashes))
          return false;
        await FileManager.writeFiles({
          '$path.pdf': bytes,
          '$path.pdf.json': utf8.encode(
            jsonEncode({
              'v': 1,
              'source': sourceHashes,
              'pdf_hash': sha256.convert(bytes).toString(),
            }),
          ),
        });
        return true;
      });
    } catch (e, stack) {
      _log.warning('PDF export remains pending', e, stack);
      return false;
    } finally {
      snapshot?.dispose();
    }
  }

  static Future<List<int>> _render(EditorCoreInfo info) async =>
      (await EditorExporter.generatePdf(info)).save();
}
