// Synthetic fixtures only. Mirrors upstream encrypt.AES defaults exactly.
import 'dart:convert';
import 'dart:io';

import 'package:crypto/crypto.dart';
import 'package:encrypt/encrypt.dart';

void main(List<String> args) {
  const password = 'FixtureOnly-encryption-2026!';
  final key = Key.fromBase64(base64Encode(List.generate(32, (i) => i)));
  final iv = IV.fromBase64(base64Encode(List.generate(16, (i) => i + 1)));
  final passwordKey = Key.fromBase64(
    base64Encode(
      sha256.convert(utf8.encode(password + r'8MnPs64@R&mF8XjWeLrD')).bytes,
    ),
  );
  final cipher = Encrypter(AES(passwordKey));
  final samples = <String, dynamic>{};
  final files = Directory('test/sbn_examples')
      .listSync()
      .whereType<File>()
      .where((f) => f.path.endsWith('.sbn2') || f.path.endsWith('.sbn'));
  for (final f in [...files, ...args.map(File.new)]) {
    final path = '/測試/${f.uri.pathSegments.last}';
    final plain = f.readAsBytesSync();
    final encrypted = f.path.endsWith('.sbn')
        ? utf8.encode(cipher.encrypt(utf8.decode(plain), iv: iv).base64)
        : cipher.encryptBytes(plain, iv: iv).bytes;
    samples[path] = {
      'remote': 'Saber/${cipher.encrypt(path, iv: iv).base16}.sbe',
      'data': base64Encode(encrypted),
      'sha256': sha256.convert(plain).toString(),
    };
  }
  stdout.write(
    jsonEncode({
      'password': password,
      'config': {
        'key': cipher.encrypt(key.base64, iv: iv).base64,
        'iv': iv.base64,
      },
      'samples': samples,
    }),
  );
}
