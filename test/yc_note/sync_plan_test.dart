import 'package:flutter_test/flutter_test.dart';
import 'package:saber/data/yc_note/sync_plan.dart';

void main() {
  YcSyncAction plan(
    String? local,
    String? remote, {
    String? base = 'old',
    int revision = 1,
    int baseRevision = 1,
    bool hasBase = true,
  }) => planYcSync(
    localHash: local,
    remoteHash: remote,
    remoteRevision: revision,
    baseHash: base,
    baseRevision: baseRevision,
    hasBase: hasBase,
  );
  test(
    'unchanged files do nothing',
    () => expect(plan('old', 'old'), YcSyncAction.none),
  );
  test(
    'local edit uploads',
    () => expect(plan('new', 'old'), YcSyncAction.upload),
  );
  test(
    'remote edit downloads',
    () => expect(plan('old', 'new', revision: 2), YcSyncAction.download),
  );
  test(
    'two edits preserve a conflict',
    () => expect(plan('mine', 'theirs', revision: 2), YcSyncAction.conflict),
  );
  test(
    'identical concurrent edits need no transfer',
    () => expect(plan('same', 'same', revision: 2), YcSyncAction.none),
  );
  test(
    'local deletion creates remote tombstone',
    () => expect(plan(null, 'old'), YcSyncAction.deleteRemote),
  );
  test(
    'remote deletion removes unchanged local',
    () => expect(plan('old', null, revision: 2), YcSyncAction.deleteLocal),
  );
  test(
    'remote deletion conflicts with local edit',
    () => expect(plan('new', null, revision: 2), YcSyncAction.conflict),
  );
  test(
    'local deletion conflicts with remote edit',
    () => expect(plan(null, 'new', revision: 2), YcSyncAction.conflict),
  );
  test(
    'new local notebook uploads',
    () => expect(
      plan(
        'new',
        null,
        base: null,
        revision: 0,
        baseRevision: 0,
        hasBase: false,
      ),
      YcSyncAction.upload,
    ),
  );
  test(
    'fresh device downloads existing notebook',
    () => expect(
      plan(
        null,
        'remote',
        base: null,
        revision: 4,
        baseRevision: 0,
        hasBase: false,
      ),
      YcSyncAction.download,
    ),
  );
  test(
    'fresh device with same filename preserves both versions',
    () => expect(
      plan(
        'local',
        'remote',
        base: null,
        revision: 4,
        baseRevision: 0,
        hasBase: false,
      ),
      YcSyncAction.conflict,
    ),
  );
  test(
    'matching deleted files do not resurrect',
    () => expect(plan(null, null, revision: 2), YcSyncAction.none),
  );
  test(
    'new notebook can reuse deliberately deleted filename',
    () => expect(
      plan('new', null, base: null, revision: 4, baseRevision: 4),
      YcSyncAction.upload,
    ),
  );
}
