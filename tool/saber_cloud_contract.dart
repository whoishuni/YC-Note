// Uses the same unmodified nextcloud 9.0.0 SDK and encryption library as Saber.
// All data is synthetic and the test server is an isolated local fixture.
import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:encrypt/encrypt.dart';
import 'package:nextcloud/core.dart';
import 'package:nextcloud/nextcloud.dart';
import 'package:nextcloud/provisioning_api.dart';
import 'package:nextcloud/webdav.dart';

var checks = 0;
void check(bool value, String label) {
  if (!value) throw StateError(label);
  checks++;
}

Future<void> main(List<String> args) async {
  final base = Uri.parse(args.single);
  if (base.host != '127.0.0.1') throw StateError('Local fixture only');
  final anonymous = NextcloudClient(base);
  final init = (await anonymous.core.clientFlowLoginV2.init()).body;
  stdout.writeln(jsonEncode({'phase': 'login', 'url': init.login}));
  await stdin.transform(utf8.decoder).transform(const LineSplitter()).first;
  final credentials = (await anonymous.core.clientFlowLoginV2.poll(
    $body: ClientFlowLoginV2PollRequestApplicationJson(
      (b) => b.token = init.poll.token,
    ),
  )).body;
  check(credentials.server == base.toString(), 'canonical server URL');
  final client = NextcloudClient(
    Uri.parse(credentials.server),
    loginName: credentials.loginName,
    appPassword: credentials.appPassword,
  );
  final user =
      (await client.provisioningApi.users.getCurrentUser()).body.ocs.data;
  check(user.id == credentials.loginName, 'full OCS user serializer');
  final avatar = (await client.core.avatar.getAvatar(
    userId: user.id,
    size: AvatarGetAvatarSize.$512,
  )).body;
  check(
    avatar.length > 8 && avatar[0] == 137 && avatar[1] == 80,
    'login avatar returns a valid PNG response',
  );
  await client.webdav.options();
  await client.webdav.mkcol(PathUri.parse('Saber'));
  final cipher = Encrypter(AES(Key.fromLength(32)));
  final iv = IV.fromLength(16);
  final config = jsonEncode({
    'key': cipher.encrypt(Key.fromLength(32).base64, iv: iv).base64,
    'iv': iv.base64,
  });
  await client.webdav.put(
    Uint8List.fromList(utf8.encode(config)),
    PathUri.parse('Saber/config.sbc'),
  );
  final path = 'Saber/${cipher.encrypt('/測試.sbn2', iv: iv).base16}.sbe';
  final data = cipher
      .encryptBytes(utf8.encode('synthetic notebook content'), iv: iv)
      .bytes;
  final mtime = DateTime.utc(2026, 1, 1, 12);
  await client.webdav.put(data, PathUri.parse(path), lastModified: mtime);
  final listed = (await client.webdav.propfind(
    PathUri.parse('Saber'),
    prop: const WebDavPropWithoutValues.fromBools(
      davGetcontentlength: true,
      davGetlastmodified: true,
    ),
  )).toWebDavFiles();
  check(listed.length == 3, 'Saber listing count');
  final file = listed.singleWhere((f) => f.path.path == path);
  check(
    file.size == data.length &&
        file.lastModified!.millisecondsSinceEpoch ==
            mtime.millisecondsSinceEpoch,
    'XML path, size and mtime parse',
  );
  final downloaded = await client.webdav.get(PathUri.parse(path));
  check(
    cipher.decrypt(Encrypted(downloaded), iv: iv) ==
        'synthetic notebook content',
    'encrypted upload download decryption round trip',
  );
  check(
    utf8.decode(await client.webdav.get(PathUri.parse('Saber/config.sbc'))) ==
        config,
    'key envelope unchanged',
  );
  await client.webdav.put(Uint8List(0), PathUri.parse(path));
  check(
    (await client.webdav.get(PathUri.parse(path))).isEmpty,
    'zero-byte tombstone',
  );
  await client.webdav.delete(PathUri.parse(path));
  check(
    (await client.webdav.propfind(PathUri.parse('Saber')))
            .toWebDavFiles()
            .length ==
        2,
    'DAV deletion',
  );
  client.close();
  anonymous.close();
  stdout.writeln('PASS: $checks actual Nextcloud SDK contract checks');
}
