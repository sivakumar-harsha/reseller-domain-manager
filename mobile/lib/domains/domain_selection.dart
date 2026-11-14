import 'package:flutter/foundation.dart';

/// Which domains the user has ticked in the Domains list, stored as stable
/// domain row ids (never names). The set lives outside the list controller, so
/// it survives filter, search and sort changes, which reload the list. Selecting
/// only marks domains: nothing here talks to the network or renews anything.
///
/// The name seen at tick time is remembered beside the id, only so the renewal
/// details can list a domain that a later filter or page has scrolled out of
/// view. Identity is always the id.
class DomainSelection extends ChangeNotifier {
  final Map<int, String?> _names = <int, String?>{};
  bool _disposed = false;

  /// Selected ids (a set, so a domain can never be selected twice).
  Set<int> get ids => Set.unmodifiable(_names.keys);

  int get count => _names.length;
  bool get isEmpty => _names.isEmpty;

  bool contains(int id) => _names.containsKey(id);

  /// Selected domains in the order they were ticked: (id, remembered name).
  List<({int id, String? name})> get entries => [
    for (final e in _names.entries) (id: e.key, name: e.value),
  ];

  void toggle(int id, {String? name}) => set(id, !contains(id), name: name);

  void set(int id, bool selected, {String? name}) {
    var changed = false;
    if (selected) {
      if (!_names.containsKey(id)) {
        _names[id] = name;
        changed = true;
      } else if (name != null && _names[id] != name) {
        _names[id] = name; // refresh silently; the count is unchanged
      }
    } else {
      changed = _names.containsKey(id);
      _names.remove(id);
    }
    if (changed) _notify();
  }

  /// Drops selected ids that are not in [existing] (domains that no longer
  /// exist in the data).
  void retainOnly(Set<int> existing) {
    final before = _names.length;
    _names.removeWhere((id, _) => !existing.contains(id));
    if (_names.length != before) _notify();
  }

  void clear() {
    if (_names.isEmpty) return;
    _names.clear();
    _notify();
  }

  void _notify() {
    if (!_disposed) notifyListeners();
  }

  @override
  void dispose() {
    _disposed = true;
    super.dispose();
  }
}
