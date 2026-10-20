import 'dart:async';
import 'dart:io';

import 'package:archive/archive_io.dart';
import 'package:collection/collection.dart';
import 'package:device_info_plus/device_info_plus.dart';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';
import 'package:intl/intl.dart' show DateFormat;
import 'package:logging/logging.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:saber/components/home/sort_button.dart';
import 'package:saber/data/prefs.dart';
import 'package:saber/data/yc_note/account.dart';
import 'package:saber/data/yc_note/file_coordinator.dart';
import 'package:saber/data/yc_note/local_batch.dart';
import 'package:saber/data/yc_note/sync.dart';
import 'package:saber/i18n/strings.g.dart';
import 'package:saber/pages/editor/editor.dart';
import 'package:saver_gallery/saver_gallery.dart';
import 'package:share_plus/share_plus.dart';

/// A collection of cross-platform utility functions for working with a virtual file system.
class FileManager {
  // disable constructor
  new _();

  static final log = Logger('FileManager');

  static const appRootDirectoryPrefix = 'YC Note';

  /// This isn't final because isolates sometimes init multiple times.
  /// Realistically, this value never changes.
  static late String documentsDirectory;
  static String? _accountNamespace;
  static bool get canWriteAccount =>
      !ycAccount.isReadOnly &&
      (_accountNamespace == null ||
          (ycAccount.canWrite && ycAccount.namespace == _accountNamespace));

  static final fileWriteStream = StreamController<FileOperation>.broadcast();

  // TODO(adil192): Implement or remove this
  static String _sanitisePath(String path) => File(path).path;

  /// A regex that matches the file names/paths of asset files,
  /// including previews, e.g. `mynote.sbn2.1`.
  static final assetFileRegex = RegExp(r'\.sbn2?\.(?:[0-9]+|p|pdf|pdf\.json)$');

  /// Forbidden names for files and directories (on any/all platforms).
  /// These patterns match the base name only (not the full path).
  /// Source: https://stackoverflow.com/a/31976060/
  static List<(String, RegExp)> _getForbiddenFilenamePatterns() => [
    (
      t.home.renameNote.noteNameForbiddenCharacters,
      RegExp(r'[<>:"/\\|?*\x00-\x1F]'),
    ),
    (
      t.home.renameNote.noteNameReserved,
      RegExp(
        r'^((con|prn|aux|nul|com[1-9]|lpt[1-9])(\..*)?)|\.+$',
        caseSensitive: false,
      ),
    ),
  ];
  static String? validateFilename(String filename) {
    if (filename.isEmpty) return t.home.renameNote.noteNameEmpty;
    for (final (error, regexp) in _getForbiddenFilenamePatterns()) {
      if (regexp.hasMatch(filename)) return error;
    }
    return null;
  }

  static Future<void> init({
    String? documentsDirectory,
    bool shouldWatchRootDirectory = true,
    String? accountNamespace,
    bool recoverTransactions = true,
  }) => YcFileCoordinator.run(() async {
    FileManager.documentsDirectory =
        documentsDirectory ?? await getDocumentsDirectory();

    _accountNamespace = accountNamespace;
    if (recoverTransactions)
      await YcLocalBatch(FileManager.documentsDirectory).recover();
    if (shouldWatchRootDirectory) await watchRootDirectory();
  });

  static Future<String> getDocumentsDirectory() async =>
      stows.customDataDir.value ?? await getDefaultDocumentsDirectory();

  static Future<String> getDefaultDocumentsDirectory() async =>
      '${(await getApplicationDocumentsDirectory()).path}/$appRootDirectoryPrefix';

  static Future<void> migrateDataDir() async {
    final oldDir = Directory(documentsDirectory);
    final newDir = Directory(await getDocumentsDirectory());
    if (oldDir.path == newDir.path) return;
    log.info('Migrating data directory from $oldDir to $newDir');

    late final oldDirEmpty = oldDir.existsSync()
        ? oldDir.listSync().isEmpty
        : true;
    late final newDirEmpty = newDir.existsSync()
        ? newDir.listSync().isEmpty
        : true;

    if (!oldDirEmpty && !newDirEmpty) {
      log.severe('New and old data directory aren\'t empty, can\'t migrate');
      return;
    }

    documentsDirectory = newDir.path;
    if (oldDirEmpty) {
      log.fine('Old data directory is empty or missing, nothing to migrate');
    } else {
      await moveDirContents(oldDir: oldDir, newDir: newDir);
      await oldDir.delete(recursive: true);
    }
  }

  static Future<void> moveDirContents({
    required Directory oldDir,
    required Directory newDir,
  }) async {
    await newDir.create(recursive: true);

    await for (final entity in oldDir.list(recursive: true)) {
      // Get the path under oldDir and map it into newDir.
      final relative = p.relative(entity.path, from: oldDir.path);
      final targetPath = p.join(newDir.path, relative);

      if (entity is Directory) {
        await Directory(targetPath).create(recursive: true);
        continue;
      }

      if (entity is File) {
        // Ensure parent exists
        await entity.parent.create(recursive: true);

        try {
          await entity.rename(targetPath);
        } on FileSystemException catch (e) {
          // Cross device move, eg. private to public on android
          const exdev = 18;
          if (e.osError?.errorCode == exdev) {
            await entity.copy(targetPath);
            await entity.delete();
          } else {
            rethrow;
          }
        }
      }
    }
  }

  @visibleForTesting
  static Future<void> watchRootDirectory() async {
    final rootDir = Directory(documentsDirectory);
    await rootDir.create(recursive: true);
    if (Platform.isIOS) return;
    rootDir.watch(recursive: true).listen((event) {
      final FileOperationType type = switch (event.type) {
        FileSystemEvent.delete => .delete,
        FileSystemEvent.create => .write,
        FileSystemEvent.modify => .write,
        FileSystemEvent.move => .write,
        _ =>
          kDebugMode
              ? throw UnimplementedError(
                  'Unhandled FileSystemEvent type: ${event.type}',
                )
              : .write,
      };
      final String path = event.path
          .replaceAll('\\', '/')
          // The path may or may not be relative,
          // so remove the root directory path to make sure it's relative.
          .replaceFirst(documentsDirectory, '');
      if (path.split('/').any((part) => part.startsWith('.'))) return;
      broadcastFileWrite(type, path);
    });
  }

  static void broadcastFileWrite(FileOperationType type, String path) async {
    if (!fileWriteStream.hasListener) return;

    // remove extension
    if (path.endsWith(Editor.extension)) {
      path = path.substring(0, path.length - Editor.extension.length);
    } else if (path.endsWith(Editor.extensionOldJson)) {
      path = path.substring(0, path.length - Editor.extensionOldJson.length);
    }

    fileWriteStream.add(FileOperation(type, path));
  }

  /// Returns the contents of the file at [filePath].
  static Future<Uint8List?> readFile(String filePath, {int retries = 3}) =>
      YcFileCoordinator.run(() async {
        filePath = _sanitisePath(filePath);

        Uint8List? result;
        final file = getFile(filePath);
        if (file.existsSync()) {
          result = await file.readAsBytes();
          if (result.isEmpty) result = null;
        } else {
          retries = 0; // don't retry if the file doesn't exist
        }

        // If result is null, try again in case the file was locked.
        if (result == null && retries > 0) {
          await Future.delayed(const Duration(milliseconds: 100));
          return readFile(filePath, retries: retries - 1);
        }
        return result;
      });

  /// Whether getFile should just return File(filePath)
  /// instead of prefixing with the documents directory.
  /// This is useful for testing when test files
  /// aren't in the documents directory.
  @visibleForTesting
  static var shouldUseRawFilePath = false;

  static File getFile(String filePath) {
    if (shouldUseRawFilePath) {
      return File(filePath);
    } else {
      assert(
        filePath.startsWith('/'),
        'Expected filePath to start with a slash, got $filePath',
      );
      return File(documentsDirectory + filePath);
    }
  }

  static Directory getRootDirectory() => Directory(documentsDirectory);

  /// Writes [toWrite] to [filePath].
  ///
  /// The file at [toPath] will have its last modified timestamp set to
  /// [lastModified], if specified.
  /// This is useful when downloading remote files, to make sure that the
  /// timestamp is the same locally and remotely.
  static Future<void> writeFile(
    String filePath,
    List<int> toWrite, {
    bool awaitWrite = false,
    bool alsoUpload = true,
    DateTime? lastModified,
  }) => YcFileCoordinator.run(() async {
    if (!canWriteAccount) return;
    filePath = _sanitisePath(filePath);
    log.fine('Writing to $filePath');

    await _saveFileAsRecentlyAccessed(filePath);

    final file = getFile(filePath);
    await _createFileDirectory(filePath);
    Future writeFuture = Future.wait([
      _writeAtomicFile(file, toWrite).then((file) async {
        if (lastModified != null) await file.setLastModified(lastModified);
      }),
      // if we're using a new format, also delete the old file
      if (filePath.endsWith(Editor.extension))
        getFile(
          '${filePath.substring(0, filePath.length - Editor.extension.length)}'
          '${Editor.extensionOldJson}',
        ).delete()
        // ignore if the file doesn't exist
        .catchError((_) => File(''), test: (e) => e is PathNotFoundException),
    ]);

    void afterWrite() {
      broadcastFileWrite(FileOperationType.write, filePath);
      if (alsoUpload) ycSync.schedule();
      if (filePath.endsWith(Editor.extension)) {
        _removeReferences(
          '${filePath.substring(0, filePath.length - Editor.extension.length)}'
          '${Editor.extensionOldJson}',
        );
      }
    }

    writeFuture = writeFuture.then((_) => afterWrite());
    await writeFuture;
  });

  static Future<File> _writeAtomicFile(File file, List<int> bytes) async {
    await file.parent.create(recursive: true);
    final staging = await file.parent.createTemp('.yc-file-');
    try {
      final input = File(p.join(staging.path, 'value'));
      await input.writeAsBytes(bytes, flush: true);
      if (!canWriteAccount) throw StateError('筆記帳號或權限已變更。');
      return await input.rename(file.path);
    } finally {
      if (staging.existsSync()) await staging.delete(recursive: true);
    }
  }

  static List<String> _sidecars(String notebook) {
    final main = getFile(notebook);
    if (!main.parent.existsSync()) return [];
    final pattern = RegExp(
      '^${RegExp.escape(p.basename(notebook))}\\.(?:[0-9]+|p|pdf|pdf\\.json)\$',
    );
    return main.parent
        .listSync(followLinks: false)
        .whereType<File>()
        .where((file) => pattern.hasMatch(p.basename(file.path)))
        .map((file) => '$notebook${file.path.substring(main.path.length)}')
        .toList();
  }

  static Future<void> _publishFiles(Map<String, File?> files) async {
    if (!canWriteAccount) throw StateError('筆記帳號或權限已變更。');
    await YcLocalBatch(documentsDirectory).apply(
      files,
      onProgress: (_) {
        if (!canWriteAccount) throw StateError('筆記帳號或權限已變更。');
      },
    );
    for (final entry in files.entries) {
      if (entry.value == null) {
        await _removeReferences(entry.key);
      } else {
        await _saveFileAsRecentlyAccessed(entry.key);
      }
      broadcastFileWrite(
        entry.value == null
            ? FileOperationType.delete
            : FileOperationType.write,
        entry.key,
      );
    }
    ycSync.schedule();
  }

  static Future<void> writeFiles(Map<String, List<int>?> changes) =>
      YcFileCoordinator.run(() async {
        if (!canWriteAccount) return;
        await Directory(documentsDirectory).create(recursive: true);
        final staging = await Directory(documentsDirectory)
            .createTemp('.yc-input-');
        try {
          final files = <String, File?>{};
          for (final change in changes.entries) {
            if (change.value == null) {
              files[change.key] = null;
            } else {
              final file = File(p.join(staging.path, '${files.length}'));
              await file.writeAsBytes(change.value!, flush: true);
              files[change.key] = file;
            }
          }
          await _publishFiles(files);
        } finally {
          if (staging.existsSync()) await staging.delete(recursive: true);
        }
      });

  /// Save one immutable notebook generation. Unchanged file-backed assets are
  /// reused without reading or rewriting large PDFs on every pen autosave.
  static Future<void> writeNotebook(
    String path,
    List<int> notebook,
    List<({File? source, Future<List<int>> Function() readBytes})> assets,
  ) => YcFileCoordinator.run(() async {
    if (!canWriteAccount) throw StateError('筆記帳號或權限已變更。');
    await Directory(documentsDirectory).create(recursive: true);
    final staging = await Directory(documentsDirectory)
        .createTemp('.yc-input-');
    try {
      final main = File(p.join(staging.path, 'main'));
      await main.writeAsBytes(notebook, flush: true);
      final files = <String, File?>{};
      for (var i = 0; i < assets.length; i++) {
        final asset = assets[i];
        final target = '$path.$i';
        if (asset.source case final File source) {
          if (p.equals(source.path, getFile(target).path) &&
              source.existsSync())
            continue;
          files[target] = source;
        } else {
          final source = File(p.join(staging.path, '$i'));
          await source.writeAsBytes(await asset.readBytes(), flush: true);
          files[target] = source;
        }
      }
      for (final old in _sidecars(path)) {
        final index = int.tryParse(old.substring(path.length + 1));
        if (index != null && index >= assets.length) files[old] = null;
      }
      if (path.endsWith(Editor.extension)) {
        final legacy =
            '${path.substring(0, path.length - Editor.extension.length)}${Editor.extensionOldJson}';
        if (getFile(legacy).existsSync()) files[legacy] = null;
      }
      files[path] = main;
      await _publishFiles(files);
    } finally {
      if (staging.existsSync()) await staging.delete(recursive: true);
    }
  });

  static Future<void> createFolder(String folderPath) =>
      YcFileCoordinator.run(() async {
        if (!canWriteAccount) return;
        folderPath = _sanitisePath(folderPath);

        final dir = Directory(documentsDirectory + folderPath);
        await dir.create(recursive: true);
      });

  static Future exportFile(
    String fileName,
    Uint8List bytes, {
    bool isImage = false,
    required BuildContext context,
  }) async {
    File? tempFile;
    Future<File> getTempFile() async {
      final tempFolder = (await getTemporaryDirectory()).path;
      final file = File('$tempFolder/$fileName');
      await file.writeAsBytes(bytes);
      return file;
    }

    if (Platform.isAndroid || Platform.isIOS) {
      if (isImage) {
        // request permission
        final permissionGranted = await _requestPhotosPermission();
        // save image to gallery
        if (permissionGranted) {
          await SaverGallery.saveImage(
            Uint8List.fromList(bytes),
            fileName: fileName,
            albumPath: 'Saber',
            skipIfExists: true,
          );
        }
      } else {
        // share file
        tempFile = await getTempFile();
        if (Platform.isIOS || Platform.isMacOS) {
          if (!context.mounted) return;
          final box = context.findRenderObject() as RenderBox;
          await SharePlus.instance.share(
            ShareParams(
              files: [XFile(tempFile.path)],
              // iOS requires a sharePositionOrigin for the share sheet to appear
              sharePositionOrigin: box.localToGlobal(Offset.zero) & box.size,
            ),
          );
        } else {
          await SharePlus.instance.share(
            ShareParams(files: [XFile(tempFile.path)]),
          );
        }
      }
    } else {
      // desktop, open save-as dialog
      await FilePicker.saveFile(
        fileName: fileName,
        initialDirectory: (await getDownloadsDirectory())?.path,
        type: FileType.custom,
        allowedExtensions: [fileName.split('.').last],
        bytes: bytes,
      );
    }

    // delete temp file if it isn't null
    await tempFile?.delete();
  }

  static Future<bool> _requestPhotosPermission() async {
    if (Platform.isIOS) {
      return await Permission.photosAddOnly.request().isGranted;
    } else if (!Platform.isAndroid) {
      return true;
    }

    final sdkInt = await DeviceInfoPlugin().androidInfo.then(
      (info) => info.version.sdkInt,
    );
    if (sdkInt > 33) {
      return await Permission.photos.request().isGranted;
    } else {
      return await Permission.storage.request().isGranted;
    }
  }

  /// Moves a file from [fromPath] to [toPath], returning its final path.
  ///
  /// If a file already exists at [toPath], [fromPath] will be suffixed with
  /// a number e.g. "file (1)". If [replaceExistingFile] is true, the existing
  /// file will be overwritten instead.
  ///
  /// If [replaceExistingFile] is true but the file is a reserved file name,
  /// the filename will be suffixed with a number instead
  /// (like if [replaceExistingFile] was false).
  static Future<String> moveFile(
    String fromPath,
    String toPath, {
    bool replaceExistingFile = false,
    bool alsoMoveAssets = true,
  }) => YcFileCoordinator.run(() async {
    if (!canWriteAccount) return fromPath;
    fromPath = _sanitisePath(fromPath);
    toPath = _sanitisePath(toPath);

    if (!toPath.contains('/')) {
      // if toPath is a relative path
      toPath = fromPath.substring(0, fromPath.lastIndexOf('/') + 1) + toPath;
    }

    if (!replaceExistingFile || Editor.isReservedPath(toPath)) {
      toPath = await suffixFilePathToMakeItUnique(
        toPath,
        currentPath: fromPath,
      );
    }

    if (fromPath == toPath) return toPath;

    final fromFile = getFile(fromPath);
    if (!fromFile.existsSync()) return fromPath;
    final files = <String, File?>{toPath: fromFile, fromPath: null};
    if (alsoMoveAssets && !assetFileRegex.hasMatch(fromPath)) {
      for (final asset in _sidecars(fromPath)) {
        files['$toPath${asset.substring(fromPath.length)}'] = getFile(asset);
        files[asset] = null;
      }
    }
    await _publishFiles(files);
    await _renameReferences(fromPath, toPath);

    return toPath;
  });

  static Future deleteFile(
    String filePath, {
    bool alsoUpload = true,
    bool alsoDeleteAssets = true,
  }) => YcFileCoordinator.run(() async {
    if (!canWriteAccount) return;
    filePath = _sanitisePath(filePath);

    final files = <String, File?>{filePath: null};
    if (alsoDeleteAssets && !assetFileRegex.hasMatch(filePath)) {
      for (final asset in _sidecars(filePath)) files[asset] = null;
    }
    await _publishFiles(files);
  });

  static Future removeUnusedAssets(String filePath, {required int numAssets}) =>
      YcFileCoordinator.run(() async {
        if (!canWriteAccount) return;
        final files = <String, File?>{};
        for (final asset in _sidecars(filePath)) {
          final index = int.tryParse(asset.substring(filePath.length + 1));
          if (index != null && index >= numAssets) files[asset] = null;
        }
        if (files.isNotEmpty) await _publishFiles(files);
      });

  static Future renameDirectory(String directoryPath, String newName) =>
      YcFileCoordinator.run(() async {
        if (!canWriteAccount) return;
        directoryPath = _sanitisePath(directoryPath);

        final directory = Directory(documentsDirectory + directoryPath);
        if (!directory.existsSync()) return;

        /// recursively find children of [directory] for [_renameReferences]
        final List<String> children = [];
        await for (final entity in directory.list(recursive: true)) {
          if (entity is File) {
            children.add(entity.path.substring(directory.path.length));
          }
        }

        final String newPath =
            directoryPath.substring(0, directoryPath.lastIndexOf('/') + 1) +
            newName;
        await directory.rename(documentsDirectory + newPath);

        for (final child in children) {
          _renameReferences(directoryPath + child, newPath + child);
          broadcastFileWrite(FileOperationType.delete, directoryPath + child);
          broadcastFileWrite(FileOperationType.write, newPath + child);
        }
      });

  static Future deleteDirectory(
    String directoryPath, [
    bool recursive = true,
  ]) => YcFileCoordinator.run(() async {
    if (!canWriteAccount) return;
    directoryPath = _sanitisePath(directoryPath);

    final directory = Directory(documentsDirectory + directoryPath);
    if (!directory.existsSync()) return;

    if (recursive) {
      final files = <String, File?>{};
      await for (final entity in directory.list(
        recursive: true,
        followLinks: false,
      )) {
        if (entity is File)
          files[entity.path.substring(documentsDirectory.length)] = null;
      }
      await _publishFiles(files);
    }

    await directory.delete(recursive: recursive);
  });

  /// Gets the children of a directory, separated into
  /// [DirectoryChildren.directories] and [DirectoryChildren.files].
  ///
  /// If [includeExtensions] is false (default), the extension will be removed
  /// from the file names. We use this to get all notes in a directory.
  ///
  /// If [includeAssets] is true, assets and previews will be included.
  /// We use this for syncing.
  ///
  /// Note: [includeAssets] can't be true without [includeExtension],
  /// since otherwise we wouldn't be able to tell the difference between notes
  /// and assets.
  static Future<DirectoryChildren?> getChildrenOfDirectory(
    String directory, {
    bool includeExtensions = false,
    bool includeAssets = false,
    SortMetric sortMetric = .nameAToZ,
  }) async {
    assert(
      !includeAssets || includeExtensions,
      'includeAssets can\'t be true without includeExtensions',
    );

    directory = _sanitisePath(directory);
    if (!directory.endsWith('/')) directory += '/';

    final List<String> directories = [], files = [];

    final dir = Directory(documentsDirectory + directory);
    if (!dir.existsSync()) return null;

    final int directoryPrefixLength = directory.endsWith('/')
        ? directory.length
        : directory.length + 1; // +1 for the trailing slash
    final allChildren = await dir
        .list()
        .map((FileSystemEntity entity) {
          final filePath = entity.path.substring(documentsDirectory.length);
          if (filePath.split('/').any((part) => part.startsWith('.')))
            return null;

          // directories don't need any further processing
          if (entity is Directory) return filePath;

          // filter out reserved files
          if (Editor.isReservedPath(filePath)) return null;

          late final isSbn2 = filePath.endsWith(Editor.extension);
          late final isSbn1 = filePath.endsWith(Editor.extensionOldJson);

          if (!includeExtensions) {
            if (isSbn2) {
              return filePath.substring(
                0,
                filePath.length - Editor.extension.length,
              );
            } else if (isSbn1) {
              return filePath.substring(
                0,
                filePath.length - Editor.extensionOldJson.length,
              );
            } else {
              return null; // filePath is name of some asset
            }
          } else if (!includeAssets) {
            final isAsset = !isSbn2 && !isSbn1;
            if (isAsset) return null;
          }

          return filePath;
        })
        .where((String? file) => file != null)
        // remove parent folder
        .map((file) => file!.substring(directoryPrefixLength))
        .toList();

    for (final child in allChildren) {
      if (FileManager.isDirectory(directory + child) &&
          !directories.contains(child)) {
        directories.add(child);
      } else if (!includeAssets && assetFileRegex.hasMatch(child)) {
        // if the file is an asset, don't add it to the list of files
      } else {
        files.add(child);
      }
    }

    switch (sortMetric) {
      case .nameAToZ:
        directories.sort();
        files.sort();
      case .nameZToA:
        directories.sort((child, other) => -child.compareTo(other));
        files.sort((child, other) => -child.compareTo(other));
      case .lastModifiedNewToOld:
        directories.sort();
        files.sortByCompare(
          (child) => lastModified(directory + child + Editor.extension),
          (date, other) => -date.compareTo(other),
        );
      case .lastModifiedOldToNew:
        directories.sort();
        files.sortBy(
          (child) => lastModified(directory + child + Editor.extension),
        );
    }

    return DirectoryChildren(directories, files);
  }

  /// Returns a list of all files recursively in the root directory.
  ///
  /// See [getChildrenOfDirectory] for more information on the parameters.
  static Future<List<String>> getAllFiles({
    bool includeExtensions = false,
    bool includeAssets = false,
  }) async {
    final allFiles = <String>[];
    final directories = <String>['/'];

    while (directories.isNotEmpty) {
      final directory = directories.removeLast();
      final children = await getChildrenOfDirectory(
        directory,
        includeExtensions: includeExtensions,
        includeAssets: includeAssets,
      );
      if (children == null) continue;

      for (final file in children.files) {
        allFiles.add('$directory$file');
      }
      for (final childDirectory in children.directories) {
        directories.add('$directory$childDirectory/');
      }
    }

    return allFiles;
  }

  static Future<List<String>> getRecentlyAccessed() async {
    if (!stows.recentFiles.loaded) await stows.recentFiles.waitUntilRead();
    // Delete entries for files that have been deleted outside of Saber
    for (final file in stows.recentFiles.value.toList()) {
      if (!doesFileExist(file)) _removeReferences(file);
    }
    return stows.recentFiles.value
        .map((String filePath) {
          if (filePath.endsWith(Editor.extension)) {
            return filePath.substring(
              0,
              filePath.length - Editor.extension.length,
            );
          } else if (filePath.endsWith(Editor.extensionOldJson)) {
            return filePath.substring(
              0,
              filePath.length - Editor.extensionOldJson.length,
            );
          } else {
            return filePath;
          }
        })
        .where(
          (String file) => !Editor.isReservedPath(file),
        ) // filter out reserved file names
        .toList();
  }

  /// Returns whether the [filePath] is a directory or file.
  /// Behaviour is undefined if [filePath] is not a valid path.
  static bool isDirectory(String filePath) {
    filePath = _sanitisePath(filePath);
    final directory = Directory(documentsDirectory + filePath);
    return directory.existsSync();
  }

  static bool doesFileExist(String filePath) {
    filePath = _sanitisePath(filePath);
    final file = getFile(filePath);
    return file.existsSync();
  }

  static DateTime lastModified(String filePath) {
    filePath = _sanitisePath(filePath);
    final file = getFile(filePath);
    if (!file.existsSync()) return DateTime(2023);
    return file.lastModifiedSync();
  }

  static Future<String> newFilePath([String parentPath = '/']) async {
    assert(parentPath.endsWith('/'));

    final DateTime now = DateTime.now();
    final String filePath =
        '$parentPath${DateFormat("yy-MM-dd").format(now)} '
        '${t.editor.untitled}';

    return await suffixFilePathToMakeItUnique(filePath);
  }

  /// Returns a unique file path by appending a number to the end of the [filePath].
  /// e.g. "/Untitled" -> "/Untitled (2)"
  ///
  /// Providing a [currentPath] means that e.g. "/Untitled (2)" being renamed
  /// to "/Untitled" will be returned as "/Untitled (2)" not "/Untitled (3)".
  ///
  /// If [currentPath] is provided, it must
  /// end with [Editor.extension] or [Editor.extensionOldJson].
  static Future<String> suffixFilePathToMakeItUnique(
    String filePath, {
    String? intendedExtension,
    String? currentPath,
  }) async {
    String newFilePath = filePath;
    bool hasExtension = false;

    if (filePath.endsWith(Editor.extension)) {
      filePath = filePath.substring(
        0,
        filePath.length - Editor.extension.length,
      );
      newFilePath = filePath;
      hasExtension = true;
      intendedExtension ??= Editor.extension;
    } else if (filePath.endsWith(Editor.extensionOldJson)) {
      filePath = filePath.substring(
        0,
        filePath.length - Editor.extensionOldJson.length,
      );
      newFilePath = filePath;
      hasExtension = true;
      intendedExtension ??= Editor.extensionOldJson;
    } else {
      intendedExtension ??= Editor.extension;
    }

    int i = 1;
    while (true) {
      if (!doesFileExist(newFilePath + Editor.extension) &&
          !doesFileExist(newFilePath + Editor.extensionOldJson))
        break;
      if (newFilePath + Editor.extension == currentPath) break;
      if (newFilePath + Editor.extensionOldJson == currentPath) break;
      i++;
      newFilePath = '$filePath ($i)';
    }

    return newFilePath + (hasExtension ? intendedExtension : '');
  }

  /// Imports a file from a sharing intent.
  ///
  /// [parentDir], if provided, must start and end with a slash.
  ///
  /// [extension], if provided, must start with a dot.
  /// If not provided, it will be inferred from the [path].
  ///
  /// Returns the file path of the imported file.
  static Future<String?> importFile(
    String path,
    String? parentDir, {
    String? extension,
    bool awaitWrite = true,
  }) => YcFileCoordinator.run(() async {
    if (!canWriteAccount) return null;
    assert(
      parentDir == null || parentDir.startsWith('/') && parentDir.endsWith('/'),
    );

    final fileExtension = extension ?? '.${path.split('.').last}';
    assert(fileExtension.length > 1 && fileExtension.startsWith('.'));

    /// The file name without its extension
    String fileName = path.split(RegExp(r'[\\/]')).last;
    fileName = fileName.substring(0, fileName.lastIndexOf('.'));
    final String importedPath;

    final imported = <String, List<int>?>{};

    if (fileExtension.toLowerCase() == '.sba') {
      final inputStream = InputFileStream(path);
      final archive = ZipDecoder().decodeStream(inputStream);

      final mainFile = archive.files.cast<ArchiveFile?>().firstWhere(
        (file) =>
            file!.name.toLowerCase().endsWith('sbn') ||
            file.name.toLowerCase().endsWith('sbn2'),
        orElse: () => null,
      );
      if (mainFile == null) {
        log.severe('Failed to find main note in sba: $path');
        return null;
      }
      final mainFileExtension = '.${mainFile.name.split('.').last}'
          .toLowerCase();
      importedPath = await suffixFilePathToMakeItUnique(
        '${parentDir ?? '/'}$fileName',
        intendedExtension: mainFileExtension,
      );
      final mainFileContents = () {
        final output = OutputMemoryStream();
        mainFile.writeContent(output);
        return output.getBytes();
      }();
      imported[importedPath + mainFileExtension] = mainFileContents;

      // now import assets
      for (final file in archive.files) {
        if (!file.isFile) continue;
        if (file == mainFile) continue;

        final extension = file.name.split('.').last;
        final assetNumber = int.tryParse(extension);
        if (assetNumber == null) continue;
        if (assetNumber < 0) continue;

        final assetBytes = () {
          final output = OutputMemoryStream();
          file.writeContent(output);
          return output.getBytes();
        }();
        imported['$importedPath$mainFileExtension.$assetNumber'] = assetBytes;
      }
    } else {
      // import sbn or sbn2
      final file = File(path);
      final fileContents = await file.readAsBytes();
      importedPath = await suffixFilePathToMakeItUnique(
        '${parentDir ?? '/'}$fileName',
        intendedExtension: fileExtension.toLowerCase(),
      );
      imported[importedPath + fileExtension.toLowerCase()] = fileContents;
    }

    await writeFiles(imported);

    return importedPath;
  });

  /// Creates the parent directories of filePath if they don't exist.
  static Future _createFileDirectory(String filePath) async {
    assert(filePath.contains('/'), 'filePath must be a path, not a file name');
    final parentDirectory = filePath.substring(0, filePath.lastIndexOf('/'));
    await Directory(documentsDirectory + parentDirectory)
        .create(recursive: true);
  }

  static Future _renameReferences(String fromPath, String toPath) async {
    // rename file in recently accessed
    bool replaced = false;
    for (int i = 0; i < stows.recentFiles.value.length; i++) {
      if (stows.recentFiles.value[i] != fromPath) continue;
      if (!replaced) {
        stows.recentFiles.value[i] = toPath;
        replaced = true;
      } else {
        stows.recentFiles.value.removeAt(i);
      }
    }
    stows.recentFiles.notifyListeners();
  }

  static Future _removeReferences(String filePath) async {
    // remove file from recently accessed
    for (int i = 0; i < stows.recentFiles.value.length; i++) {
      if (stows.recentFiles.value[i] != filePath) continue;
      stows.recentFiles.value.removeAt(i);
    }
    stows.recentFiles.notifyListeners();
  }

  static Future _saveFileAsRecentlyAccessed(String filePath) async {
    // don't add assets to recently accessed
    if (assetFileRegex.hasMatch(filePath)) return;

    stows.recentFiles.value.remove(filePath);
    stows.recentFiles.value.insert(0, filePath);
    if (stows.recentFiles.value.length > maxRecentlyAccessedFiles)
      stows.recentFiles.value.removeLast();

    stows.recentFiles.notifyListeners();
  }

  static const maxRecentlyAccessedFiles = 30;
}

class DirectoryChildren {
  final List<String> directories;
  final List<String> files;

  new(this.directories, this.files);

  bool onlyOneChild() => directories.length + files.length <= 1;

  bool get isEmpty => directories.isEmpty && files.isEmpty;
  bool get isNotEmpty => !isEmpty;
}

enum FileOperationType { write, delete }

class FileOperation {
  final FileOperationType type;
  final String filePath;

  const new(this.type, this.filePath);
}
