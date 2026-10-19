import 'dart:io';
import 'dart:typed_data';

import 'package:path/path.dart' as p;

/// Preserves edits made before access changed, without touching the source note.
/// Hidden staging prevents a partial notebook from appearing in lists or sync.
Future<String> preserveYcNotebook({
  required String root,
  required String sourcePath,
  required Uint8List notebook,
  required List<Future<List<int>>> assets,
}) async {
  final name = p.basename(sourcePath);
  if (!name.endsWith('.sbn2') || name.startsWith('.')) {
    throw const FormatException('Invalid recovery notebook name');
  }
  final contents = await Future.wait(assets);
  final staging = await Directory(root).createTemp('.yc-recovery-');
  try {
    await File(p.join(staging.path, name)).writeAsBytes(notebook, flush: true);
    for (var i = 0; i < contents.length; i++) {
      await File(p.join(staging.path, '$name.$i'))
          .writeAsBytes(contents[i], flush: true);
    }
    final timestamp = DateTime.now().toUtc().toIso8601String().replaceAll(
      ':',
      '-',
    );
    final unique = p.basename(staging.path).substring('.yc-recovery-'.length);
    final folder = p.join(root, '本機保留', '$timestamp-$unique');
    await Directory(p.dirname(folder)).create(recursive: true);
    await staging.rename(folder);
    return '/${p.relative(p.join(folder, name), from: root).replaceAll(p.separator, '/')}';
  } finally {
    if (staging.existsSync()) await staging.delete(recursive: true);
  }
}
