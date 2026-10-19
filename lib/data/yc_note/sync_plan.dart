/// Three-way synchronization uses content hashes and server revisions, never clocks.
enum YcSyncAction {
  none,
  upload,
  download,
  deleteRemote,
  deleteLocal,
  conflict,
}

YcSyncAction planYcSync({
  required String? localHash,
  required String? remoteHash,
  required int remoteRevision,
  required String? baseHash,
  required int baseRevision,
  required bool hasBase,
}) {
  if (localHash == remoteHash) return YcSyncAction.none;
  final localChanged = localHash != baseHash;
  final remoteChanged = !hasBase
      ? remoteHash != null
      : remoteRevision != baseRevision;
  if (localChanged && remoteChanged) return YcSyncAction.conflict;
  if (localChanged)
    return localHash == null ? YcSyncAction.deleteRemote : YcSyncAction.upload;
  if (remoteChanged)
    return remoteHash == null
        ? YcSyncAction.deleteLocal
        : YcSyncAction.download;
  return YcSyncAction.none;
}
