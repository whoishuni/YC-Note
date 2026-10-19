import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';
import 'package:go_router/go_router.dart';
import 'package:saber/data/routes.dart';
import 'package:saber/data/yc_note/account.dart';
import 'package:saber/data/yc_note/sync.dart';
import 'package:url_launcher/url_launcher.dart';

class YcLoginPage extends StatefulWidget {
  const new({super.key});
  @override
  State<YcLoginPage> createState() => _YcLoginPageState();
}

class _YcLoginPageState extends State<YcLoginPage> {
  final _username = TextEditingController();
  final _password = TextEditingController();
  final _device = TextEditingController(text: '我的 iPad');
  var _busy = false;
  String? _error;

  Future<void> _login() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      if (_username.text.trim().isEmpty ||
          _password.text.isEmpty ||
          _device.text.trim().isEmpty)
        throw const YcApiException(422, '請填寫帳號、密碼與裝置名稱。');
      await ycAccount.login(_username.text, _password.text, _device.text);
      _password.clear();
      if (mounted && ycAccount.canUse) context.go(HomeRoutes.routes.first.path);
    } catch (e) {
      if (mounted)
        setState(
          () => _error = e is YcApiException ? e.message : '無法登入，請確認網路連線後再試。',
        );
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => ListenableBuilder(
    listenable: Listenable.merge([ycAccount, ycSync]),
    builder: (context, _) => Scaffold(
      appBar: AppBar(title: const Text('YC Note')),
      body: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(28),
          child: SizedBox(
            width: 420,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Center(
                  child: SvgPicture.asset(
                    'assets/icon/icon.svg',
                    width: 86,
                    height: 86,
                  ),
                ),
                const SizedBox(height: 24),
                Text(
                  ycAccount.hasToken ? '帳號' : '登入',
                  style: Theme.of(context).textTheme.headlineSmall,
                ),
                const SizedBox(height: 12),
                const Text(ycServer),
                const SizedBox(height: 24),
                if (!ycAccount.hasToken ||
                    {'expired', 'revoked'}.contains(ycAccount.status)) ...[
                  TextField(
                    controller: _username,
                    autofillHints: const [AutofillHints.username],
                    enabled: !_busy,
                    decoration: const InputDecoration(labelText: '網站帳號'),
                  ),
                  const SizedBox(height: 16),
                  TextField(
                    controller: _password,
                    obscureText: true,
                    autofillHints: const [AutofillHints.password],
                    enabled: !_busy,
                    decoration: const InputDecoration(labelText: '密碼'),
                    onSubmitted: (_) {
                      if (!_busy) unawaited(_login());
                    },
                  ),
                  const SizedBox(height: 16),
                  TextField(
                    controller: _device,
                    maxLength: 100,
                    enabled: !_busy,
                    decoration: const InputDecoration(labelText: '裝置名稱'),
                  ),
                  const SizedBox(height: 16),
                  FilledButton(
                    onPressed: _busy ? null : _login,
                    child: Text(_busy ? '登入中…' : '登入'),
                  ),
                ] else ...[
                  Text('帳號：${ycAccount.username}'),
                  Text(ycAccount.canWrite ? '可編輯' : '唯讀'),
                  const SizedBox(height: 8),
                  SelectableText('裝置代碼：${ycAccount.deviceId}'),
                  const SizedBox(height: 16),
                  Text(
                    ycAccount.canUse
                        ? ycSync.message
                        : ycAccount.status == 'pending'
                        ? '等待管理員核准裝置。'
                        : '驗證效期已結束，請連線重新驗證。',
                  ),
                  const SizedBox(height: 16),
                  if (ycAccount.canUse)
                    FilledButton(
                      onPressed: () => context.go(HomeRoutes.routes.first.path),
                      child: const Text('筆記'),
                    ),
                  OutlinedButton(
                    onPressed: _busy
                        ? null
                        : () async {
                            setState(() => _busy = true);
                            await ycAccount.refresh();
                            if (mounted) setState(() => _busy = false);
                          },
                    child: const Text('重新驗證'),
                  ),
                  if (ycAccount.canUse)
                    OutlinedButton(
                      onPressed: ycSync.busy ? null : ycSync.sync,
                      child: const Text('立即同步'),
                    ),
                  TextButton(
                    onPressed: ycAccount.signingOut ? null : ycAccount.logout,
                    child: const Text('登出此裝置'),
                  ),
                ],
                if (_error ?? ycAccount.error case final String error)
                  Padding(
                    padding: const EdgeInsets.only(top: 16),
                    child: Text(
                      error,
                      style: TextStyle(
                        color: Theme.of(context).colorScheme.error,
                      ),
                    ),
                  ),
                const SizedBox(height: 18),
                TextButton(
                  onPressed: () => launchUrl(
                    Uri.parse(ycServer),
                    mode: LaunchMode.externalApplication,
                  ),
                  child: const Text('管理網站'),
                ),
              ],
            ),
          ),
        ),
      ),
    ),
  );

  @override
  void dispose() {
    _username.dispose();
    _password.dispose();
    _device.dispose();
    super.dispose();
  }
}

class YcAccountTile extends StatelessWidget {
  const new({super.key});
  @override
  Widget build(BuildContext context) => ListenableBuilder(
    listenable: Listenable.merge([ycAccount, ycSync]),
    builder: (context, _) => ListTile(
      leading: const Icon(Icons.verified_user_outlined),
      title: Text('YC Note · ${ycAccount.username}'),
      subtitle: Text(ycSync.message),
      onTap: () => context.push(RoutePaths.login),
      trailing: IconButton(
        tooltip: '立即同步',
        onPressed: ycSync.busy ? null : ycSync.sync,
        icon: ycSync.busy
            ? const SizedBox(
                width: 20,
                height: 20,
                child: CircularProgressIndicator(strokeWidth: 2),
              )
            : const Icon(Icons.sync),
      ),
    ),
  );
}

/// Wait for a download batch before opening a notebook, so attachments and
/// notebook contents cannot change underneath a newly mounted editor.
class YcEditorGate extends StatefulWidget {
  const new({super.key, required this.builder});
  final Widget Function() builder;
  @override
  State<YcEditorGate> createState() => _YcEditorGateState();
}

class _YcEditorGateState extends State<YcEditorGate> {
  var _ready = false;
  @override
  void initState() {
    super.initState();
    unawaited(_open());
  }

  Future<void> _open() async {
    if (ycSync.busy) await ycSync.sync();
    if (!mounted) return;
    ycSync.editing = true;
    setState(() => _ready = true);
  }

  @override
  Widget build(BuildContext context) => _ready
      ? widget.builder()
      : const Scaffold(
          body: Center(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                CircularProgressIndicator(),
                SizedBox(height: 16),
                Text('正在完成同步，稍後開啟筆記…'),
              ],
            ),
          ),
        );
  @override
  void dispose() {
    ycSync.editing = false;
    ycSync.schedule();
    super.dispose();
  }
}
