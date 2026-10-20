import 'dart:async';

/// Serializes account file mutations and sync captures, including nested work.
final class YcFileCoordinator {
  static final _zoneKey = Object();
  static Future<void> _tail = Future.value();

  static Future<T> run<T>(Future<T> Function() action) {
    if ((Zone.current[_zoneKey] as _FileLease?)?.active == true)
      return action();
    final before = _tail;
    final released = Completer<void>();
    _tail = released.future;
    return () async {
      await before;
      final lease = _FileLease();
      try {
        return await runZoned(action, zoneValues: {_zoneKey: lease});
      } finally {
        lease.active = false;
        released.complete();
      }
    }();
  }
}

final class _FileLease {
  var active = true;
}
