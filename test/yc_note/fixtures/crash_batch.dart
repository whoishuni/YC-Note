import 'dart:async';
import 'dart:io';

import 'package:saber/data/yc_note/local_batch.dart';

Future<void> main(List<String> args) async {
  final root = args[0];
  await YcLocalBatch(root).apply(
    {
      '/note.sbn2': File('$root/input-main'),
      '/note.sbn2.0': File('$root/input-asset'),
      YcLocalBatch.statePath: File('$root/input-state'),
    },
    onProgress: (n) async {
      if (n != 1) return;
      stdout.writeln('READY');
      await stdout.flush();
      await Completer<void>().future;
    },
  );
}
