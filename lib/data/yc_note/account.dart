import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:math';

import 'package:crypto/crypto.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;
import 'package:path_provider/path_provider.dart';
import 'package:saber/data/file_manager/file_manager.dart';
import 'package:saber/data/prefs.dart';

const ycServer = String.fromEnvironment(
  'YC_NOTE_SERVER',
  defaultValue: 'https://yckuo.com/yc_note',
);

class YcApiException implements Exception {
  const new(this.status, this.message);
  final int status;
  final String message;
  @override
  String toString() => message;
}

class YcApi {
  new({required this.token, http.Client? client})
    : client = client ?? http.Client();
  final String token;
  final http.Client client;

  Uri uri(String action, [Map<String, String> query = const {}]) {
    final root = Uri.parse(ycServer);
    if (root.scheme != 'https' ||
        root.host.isEmpty ||
        root.hasQuery ||
        root.hasFragment) {
      throw StateError('YC Note server must be an HTTPS URL.');
    }
    return Uri.parse('${ycServer.replaceFirst(RegExp(r'/+$'), '')}/api.php')
        .replace(queryParameters: {'action': action, ...query});
  }

  Future<http.Response> request(
    String method,
    String action, {
    Map<String, String> query = const {},
    Map<String, String> headers = const {},
    Object? body,
  }) async {
    final request = http.Request(method, uri(action, query))
      ..followRedirects = false
      ..headers.addAll({
        if (token.isNotEmpty) 'Authorization': 'Bearer $token',
        ...headers,
      });
    if (body is List<int>) request.bodyBytes = body;
    if (body is String) request.body = body;
    final response = await http.Response.fromStream(
      await client.send(request).timeout(const Duration(seconds: 45)),
    ).timeout(const Duration(seconds: 90));
    if (response.statusCode < 200 || response.statusCode >= 300) {
      var message = '伺服器暫時無法使用（${response.statusCode}）。';
      try {
        final data = jsonDecode(response.body) as Map<String, dynamic>;
        message = data['error'] as String? ?? message;
      } catch (_) {
        /* A proxy may return HTML instead of API JSON. */
      }
      throw YcApiException(response.statusCode, message);
    }
    return response;
  }

  Future<Map<String, dynamic>> json(
    String method,
    String action, {
    Map<String, String> query = const {},
    Map<String, dynamic>? data,
  }) async => jsonDecode(
    (await request(
      method,
      action,
      query: query,
      headers: data == null ? const {} : {'Content-Type': 'application/json'},
      body: data == null ? null : jsonEncode(data),
    )).body,
  ) as Map<String, dynamic>;
}

final ycAccount = YcAccount();

class YcAccount extends ChangeNotifier {
  final _storage = const FlutterSecureStorage();
  Map<String, dynamic> _session = {};
  var token = '';
  var signingOut = false;
  var _refreshing = false;
  Completer<void>? _refreshDone;
  String? error;
  Timer? _timer;
  String? _mountedAccount;
  Future<void> Function()? beforeLogout;

  String get username => _session['username'] as String? ?? '';
  String get deviceId => _session['device_id'] as String? ?? '';
  int get userId => (_session['user_id'] as num?)?.toInt() ?? 0;
  String get status => _session['status'] as String? ?? 'logged_out';
  bool get hasToken => token.isNotEmpty;
  bool get canWrite => canUse && _session['can_write'] == true;
  bool get isReadOnly => hasToken && !canWrite;
  String get role => _session['yc_role'] as String? ?? 'user';

  @visibleForTesting
  void setSessionForTesting(Map<String, dynamic> session) {
    _session = session;
    token = session.isEmpty ? '' : 'fixture-token';
    _mountedAccount = session.isEmpty ? null : namespace;
    notifyListeners();
  }

  String get namespace =>
      '${sha256.convert(utf8.encode(ycServer)).toString().substring(0, 16)}_$userId';
  bool get canUse {
    if (signingOut ||
        token.isEmpty ||
        status != 'approved' ||
        _mountedAccount != namespace)
      return false;
    final now = DateTime.now().millisecondsSinceEpoch ~/ 1000;
    final verified = (_session['verified_at'] as num?)?.toInt() ?? 0;
    final lease = (_session['offline_lease_seconds'] as num?)?.toInt() ?? 0;
    final expires = (_session['expires_at'] as num?)?.toInt() ?? 0;
    return now >= verified && now < verified + lease && now < expires;
  }

  Future<void> initialize() async {
    final saved = await _storage.read(key: 'yc_note_session_v1');
    if (saved != null) {
      try {
        _session = jsonDecode(saved) as Map<String, dynamic>;
        // A build pointing to another server cannot reuse this server's token.
        if (_session['server'] == ycServer)
          token = _session['token'] as String? ?? '';
        else
          _session = {};
      } catch (_) {
        _session = {};
      }
    }
    if (hasToken) await refresh();
    _timer ??= Timer.periodic(
      const Duration(minutes: 1),
      (_) => unawaited(refresh()),
    );
  }

  Future<void> _save() async => _storage.write(
    key: 'yc_note_session_v1',
    value: jsonEncode({..._session, 'token': token, 'server': ycServer}),
  );

  Future<void> _mount() async {
    if (status != 'approved' || _mountedAccount == namespace) return;
    final root = await getApplicationDocumentsDirectory();
    await FileManager.init(
      documentsDirectory: '${root.path}/YC Note/accounts/$namespace',
      shouldWatchRootDirectory: false,
      accountNamespace: namespace,
    );
    await Directory(FileManager.documentsDirectory).create(recursive: true);
    await stows.recentFiles.waitUntilRead();
    stows.recentFiles.value = [];
    _mountedAccount = namespace;
  }

  Future<void> login(
    String username,
    String password,
    String deviceName,
  ) async {
    await _refreshDone?.future;
    if (signingOut) return;
    error = null;
    var installation = await _storage.read(key: 'yc_note_installation_v1');
    if (installation == null) {
      final random = Random.secure();
      installation = List.generate(
        32,
        (_) => random.nextInt(256).toRadixString(16).padLeft(2, '0'),
      ).join();
      await _storage.write(key: 'yc_note_installation_v1', value: installation);
    }
    final api = YcApi(token: '');
    try {
      final result = await api.json(
        'POST',
        'login',
        data: {
          'username': username.trim(),
          'password': password,
          'installation_id': installation,
          'device_name': deviceName.trim(),
          'platform': Platform.operatingSystem,
        },
      );
      token = result.remove('token') as String;
      _session = {
        ...result,
        'verified_at': DateTime.now().millisecondsSinceEpoch ~/ 1000,
      };
      await _mount();
      await _save();
      notifyListeners();
    } finally {
      api.client.close();
    }
  }

  Future<void> refresh() async {
    if (!hasToken || signingOut || _refreshing) {
      notifyListeners();
      return;
    }
    _refreshing = true;
    _refreshDone = Completer<void>();
    final api = YcApi(token: token);
    try {
      final result = await api.json('GET', 'session');
      _session = {
        ...result,
        'verified_at': DateTime.now().millisecondsSinceEpoch ~/ 1000,
      };
      error = null;
      await _mount();
      await _save();
    } on YcApiException catch (e) {
      error = e.message;
      if (e.status == 401 || e.status == 403) {
        _session['status'] = e.status == 401 ? 'expired' : 'revoked';
        _session['verified_at'] = 0;
        await _save();
      }
    } catch (_) {
      error = '目前無法連線；已認證裝置可在驗證效期內離線使用。';
    } finally {
      api.client.close();
      _refreshing = false;
      _refreshDone!.complete();
      _refreshDone = null;
      notifyListeners();
    }
  }

  Future<void> logout() async {
    signingOut = true;
    notifyListeners();
    await _refreshDone?.future;
    await beforeLogout?.call();
    final api = YcApi(token: token);
    try {
      await api.json('POST', 'logout');
    } catch (_) {
      /* Locally forget expired/offline credentials too. */
    } finally {
      api.client.close();
    }
    token = '';
    _session = {};
    _mountedAccount = null;
    await _storage.delete(key: 'yc_note_session_v1');
    signingOut = false;
    notifyListeners();
  }
}
