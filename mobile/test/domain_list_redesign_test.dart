import 'dart:async';
import 'dart:convert';

import 'package:domain_manager/auth/auth_controller.dart';
import 'package:domain_manager/auth/token_storage.dart';
import 'package:domain_manager/core/api_client.dart';
import 'package:domain_manager/domains/balance.dart';
import 'package:domain_manager/domains/bulk_sync.dart';
import 'package:domain_manager/domains/domain_controller.dart';
import 'package:domain_manager/domains/domain_selection.dart';
import 'package:domain_manager/domains/expiry.dart';
import 'package:domain_manager/screens/domain_list_screen.dart';
import 'package:domain_manager/theme/app_theme.dart';
import 'package:domain_manager/widgets/outcome_banner.dart';
import 'package:domain_manager/widgets/state_view.dart';
import 'package:domain_manager/widgets/status_badge.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

// Fake HTTP only: no network, no backend, no ResellerClub traffic.

class _Storage implements TokenStorage {
  TokenPair? tokens = const TokenPair(accessToken: 'a1', refreshToken: 'r1');
  @override
  Future<TokenPair?> read() async => tokens;
  @override
  Future<void> write(TokenPair tokens) async => this.tokens = tokens;
  @override
  Future<void> clear() async => tokens = null;
}

const _longName =
    'a-very-long-subdomain-name-for-wrapping-test.example-company-holdings.com';
const _longStatus = 'Pending Delete Restorable Transfer Prohibited Hold';

Map<String, Object?> _row(
  int id, {
  String? name,
  String? status = 'Active',
  String? expires = '2030-01-15T00:00:00Z',
  bool missing = false,
}) => {
  'id': id,
  'domain_name': name ?? 'site$id.com',
  'status': status,
  'expires_at': expires,
  'auto_renew': null,
  'privacy_enabled': true,
  'registrar_lock': false,
  'is_missing': missing,
  'last_synced_at': '2026-05-01T10:30:00Z',
};

http.Response _json(int status, Object body) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

/// A programmable fake backend. [rows] is served page by page; [total] is
/// what the meta reports.
class _Server {
  List<Map<String, Object?>> rows = [for (var i = 1; i <= 5; i++) _row(i)];
  int? total;
  bool failList = false;
  bool failBalance = false;
  Completer<void>? gate;
  final requests = <http.Request>[];

  List<http.Request> get listRequests =>
      requests.where((r) => r.url.path.endsWith('/domains')).toList();

  Future<http.Response> handle(http.Request r) async {
    requests.add(r);
    if (r.url.path.endsWith('/balance')) {
      if (failBalance) return _json(502, {'error': 'provider_error'});
      return _json(200, {
        'selling_balance': '6244.40',
        'selling_currency': 'USD',
        'selling_locked_balance': '0',
        'accounting_balance': '273867.87',
        'accounting_currency': 'INR',
        'accounting_locked_balance': '0',
      });
    }
    if (gate != null) await gate!.future;
    if (failList) return _json(500, {'error': 'server_error'});
    final q = r.url.queryParameters;
    final page = int.parse(q['page'] ?? '1');
    final perPage = int.parse(q['per_page'] ?? '20');
    final term = q['q'];
    var data = rows;
    if (term != null && term.isNotEmpty) {
      data = [
        for (final d in rows)
          if ((d['domain_name']! as String).contains(term)) d,
      ];
    }
    final all = term != null && term.isNotEmpty ? data.length : total;
    final count = all ?? data.length;
    final from = (page - 1) * perPage;
    final slice = data.skip(from).take(perPage).toList();
    return _json(200, {
      'data': slice,
      'meta': {
        'page': page,
        'per_page': perPage,
        'total': count,
        'total_pages': (count / perPage).ceil(),
      },
      'last_synced_at': '2026-05-01T10:30:00Z',
    });
  }
}

class _Harness {
  _Harness(this.server, {this.opened}) {
    auth = AuthController(
      api: ApiClient(
        client: MockClient(server.handle),
        baseUrl: 'http://test/api/v1/',
      ),
      storage: _Storage(),
    )..status = AuthStatus.signedIn;
    controller = DomainController(api: auth.api);
    bulk = BulkSyncController(api: auth.api);
    balance = BalanceController(api: auth.api);
  }

  final _Server server;
  final void Function(String name)? opened;
  late final AuthController auth;
  late final DomainController controller;
  late final BulkSyncController bulk;
  late final BalanceController balance;
  final selection = DomainSelection();
  int renewTaps = 0;

  Widget get widget => MaterialApp(
    theme: AppTheme.light,
    home: Scaffold(
      body: DomainListScreen(
        controller: controller,
        selection: selection,
        bulk: bulk,
        balance: balance,
        onOpen: (d) => opened?.call(d.domainName),
        onRenewSelected: () => renewTaps++,
        now: () => DateTime.utc(2026, 5, 1),
      ),
    ),
  );

  Future<void> pump(WidgetTester t, {bool settle = true}) async {
    await t.pumpWidget(widget);
    controller.load();
    balance.load();
    if (settle) await t.pumpAndSettle();
  }

  void dispose() {
    controller.dispose();
    bulk.dispose();
    balance.dispose();
    selection.dispose();
  }
}

void _view(WidgetTester t, Size size, double scale) {
  t.view.physicalSize = size;
  t.view.devicePixelRatio = 1;
  t.platformDispatcher.textScaleFactorTestValue = scale;
  addTearDown(t.view.reset);
  addTearDown(t.platformDispatcher.clearTextScaleFactorTestValue);
}

const _sizes = [
  Size(320, 568),
  Size(360, 740),
  Size(412, 892),
  Size(1024, 768),
];
const _scales = [1.0, 2.0];

String _dim(Size s, double scale) =>
    '${s.width.toInt()}x${s.height.toInt()} at ${scale}x';

void main() {
  _tests();
  _compactTests();
  group('no overflow across sizes, text scales and states', () {
    // Each state builds the harness and reaches the screen state to check.
    final states = <String, Future<_Harness> Function(WidgetTester)>{
      'loaded list': (t) async {
        final h = _Harness(_Server());
        await h.pump(t);
        return h;
      },
      'long name, expiry and status': (t) async {
        final s = _Server()
          ..rows = [
            _row(1, name: _longName, status: _longStatus),
            _row(2, expires: '2020-01-01T00:00:00Z', status: _longStatus),
            _row(3, expires: null, status: null),
          ];
        final h = _Harness(s);
        await h.pump(t);
        return h;
      },
      'missing domain': (t) async {
        final s = _Server()
          ..rows = [_row(1, missing: true, name: _longName), _row(2)];
        final h = _Harness(s);
        await h.pump(t);
        return h;
      },
      'selection active': (t) async {
        final h = _Harness(_Server());
        await h.pump(t);
        h.selection.set(1, true, name: 'site1.com');
        h.selection.set(2, true, name: 'site2.com');
        await t.pump();
        return h;
      },
      'search open with text': (t) async {
        final h = _Harness(_Server());
        await h.pump(t);
        await t.tap(find.byTooltip('Search domains'));
        await t.pump();
        await t.enterText(find.byType(TextField), 'site1');
        await t.pumpAndSettle(const Duration(seconds: 1));
        return h;
      },
      'empty search result': (t) async {
        final h = _Harness(_Server());
        await h.pump(t);
        await t.tap(find.byTooltip('Search domains'));
        await t.pump();
        await t.enterText(find.byType(TextField), 'zzz-nothing');
        await t.pumpAndSettle(const Duration(seconds: 1));
        return h;
      },
      'empty filtered result': (t) async {
        final s = _Server()..rows = [];
        final h = _Harness(s);
        await h.pump(t);
        h.controller.setExpiry(ExpiryFilter.expired);
        await t.pumpAndSettle();
        return h;
      },
      'initial loading': (t) async {
        final s = _Server()..gate = Completer<void>();
        final h = _Harness(s);
        await h.pump(t, settle: false);
        await t.pump();
        addTearDown(() {
          if (!s.gate!.isCompleted) s.gate!.complete();
        });
        return h;
      },
      'initial error': (t) async {
        final s = _Server()..failList = true;
        final h = _Harness(s);
        await h.pump(t);
        return h;
      },
      'error with rows': (t) async {
        final s = _Server();
        final h = _Harness(s);
        await h.pump(t);
        s.failList = true;
        await h.controller.refresh();
        await t.pumpAndSettle();
        return h;
      },
      'load-more loading': (t) async {
        final s = _Server()..rows = [for (var i = 1; i <= 45; i++) _row(i)];
        final h = _Harness(s);
        await h.pump(t);
        s.gate = Completer<void>();
        addTearDown(() {
          if (!s.gate!.isCompleted) s.gate!.complete();
        });
        h.controller.loadMore();
        await t.pump();
        return h;
      },
      'load-more error': (t) async {
        final s = _Server()..rows = [for (var i = 1; i <= 45; i++) _row(i)];
        final h = _Harness(s);
        await h.pump(t);
        s.failList = true;
        await h.controller.loadMore();
        await t.pumpAndSettle();
        return h;
      },
      'sync panel running': (t) async {
        final h = _Harness(_Server());
        await h.pump(t);
        h.bulk.phase = BulkPhase.running;
        h.bulk.progress = const BatchProgress(
          batchId: 'b',
          status: 'running',
          total: 120,
          processed: 40,
          succeeded: 38,
          failed: 2,
          skipped: 0,
          currentName: _longName,
        );
        // ignore: invalid_use_of_protected_member
        h.bulk.notifyListeners();
        await t.pump();
        return h;
      },
      'sync panel finished': (t) async {
        final h = _Harness(_Server());
        await h.pump(t);
        h.bulk.phase = BulkPhase.finished;
        h.bulk.progress = const BatchProgress(
          batchId: 'b',
          status: 'completed',
          total: 120,
          processed: 120,
          succeeded: 118,
          failed: 2,
          skipped: 0,
          newDomains: 3,
          discoveryState: 'done',
          discoveryRunStatus: 'success',
          failures: [
            BatchFailure(domainId: 7, domainName: _longName, error: 'x'),
          ],
        );
        // ignore: invalid_use_of_protected_member
        h.bulk.notifyListeners();
        await t.pump();
        return h;
      },
      'four-digit total': (t) async {
        final s = _Server()..total = 1234;
        final h = _Harness(s);
        await h.pump(t);
        return h;
      },
      'balance unavailable': (t) async {
        final s = _Server()..failBalance = true;
        final h = _Harness(s);
        await h.pump(t);
        return h;
      },
    };

    for (final size in _sizes) {
      for (final scale in _scales) {
        for (final e in states.entries) {
          testWidgets('${e.key}: ${_dim(size, scale)}', (t) async {
            _view(t, size, scale);
            final h = await e.value(t);
            addTearDown(h.dispose);
            expect(t.takeException(), isNull);
            // Everything stays scrollable/reachable: no exception while the
            // list is scrolled to its end either.
            final list = find.byType(Scrollable);
            if (list.evaluate().isNotEmpty) {
              await t.drag(list.last, const Offset(0, -2000));
              await t.pump();
              expect(t.takeException(), isNull);
            }
            // Let any request still held back finish (no pending timers).
            h.server.gate?.complete();
            await t.pumpAndSettle();
            expect(t.takeException(), isNull);
          });
        }
      }
    }
  });
}

// The Sync button is gone; the more-actions menu takes its place in the row.
final _syncButton = find.byKey(const ValueKey('more-actions'));

Finder _box(int id) => find.byKey(ValueKey('select-$id'));
Finder _chip(ExpiryFilter f) =>
    find.byKey(ValueKey('expiry-chip-${f.apiValue}'));

void _small(WidgetTester t, {double scale = 1}) =>
    _view(t, const Size(320, 568), scale);

Future<_Harness> _loaded(
  WidgetTester t, {
  _Server? server,
  void Function(String)? opened,
}) async {
  final h = _Harness(server ?? _Server(), opened: opened);
  addTearDown(h.dispose);
  await h.pump(t);
  return h;
}

void _tests() {
  group('domain row', () {
    testWidgets('shows name, expiry date, relative badge and status', (
      t,
    ) async {
      final s = _Server()
        ..rows = [_row(1, name: 'alpha.com', expires: '2026-05-04T00:00:00Z')];
      await _loaded(t, server: s);
      expect(find.text('alpha.com'), findsOneWidget);
      expect(find.text('Expires 2026-05-04'), findsOneWidget);
      expect(find.text('Active'), findsOneWidget);
      expect(find.byType(StatusBadge), findsOneWidget);
    });

    testWidgets('relative labels and severity tones follow the ladder', (
      t,
    ) async {
      final s = _Server()
        ..rows = [
          _row(1, name: 'gone.com', expires: '2026-04-20T00:00:00Z'),
          _row(2, name: 'soon.com', expires: '2026-05-04T00:00:00Z'),
          _row(3, name: 'month.com', expires: '2026-05-21T00:00:00Z'),
          _row(4, name: 'later.com', expires: '2027-05-01T00:00:00Z'),
          _row(5, name: 'none.com', expires: null),
        ];
      await _loaded(t, server: s);
      BadgeTone tone(String label) =>
          t.widget<StatusBadge>(find.widgetWithText(StatusBadge, label)).tone;
      expect(find.text('Expired 2026-04-20'), findsOneWidget);
      expect(tone('11 days ago'), BadgeTone.danger);
      expect(tone('in 3 days'), BadgeTone.warning);
      expect(tone('in 20 days'), BadgeTone.caution);
      expect(tone('in 365 days'), BadgeTone.success);
      expect(tone('Unknown'), BadgeTone.neutral);
      expect(find.text('Expiry unknown'), findsOneWidget);
    });

    testWidgets('long name, date and status wrap, never ellipsised', (t) async {
      _small(t, scale: 2);
      final s = _Server()
        ..rows = [_row(1, name: _longName, status: _longStatus)];
      await _loaded(t, server: s);
      for (final text in [_longName, _longStatus, 'Expires 2030-01-15']) {
        final w = t.widget<Text>(find.text(text));
        expect(w.maxLines, isNull, reason: text);
        expect(w.overflow, isNot(TextOverflow.ellipsis), reason: text);
        expect(w.softWrap, isNot(false), reason: text);
      }
      // Wrapped onto several lines rather than squeezed or clipped.
      expect(t.getSize(find.text(_longName)).height, greaterThan(60));
      expect(t.takeException(), isNull);
    });

    testWidgets('a missing domain says so, and cannot be ticked', (t) async {
      final s = _Server()..rows = [_row(1, missing: true), _row(2)];
      final h = await _loaded(t, server: s);
      expect(find.byIcon(Icons.warning_amber), findsOneWidget);
      expect(find.text('Missing from provider'), findsOneWidget);
      expect(
        find.byTooltip('Not listed by the provider in the last complete sync'),
        findsOneWidget,
      );
      expect(t.widget<Checkbox>(_box(1)).onChanged, isNull);
      await t.tap(_box(1), warnIfMissed: false);
      await t.pump();
      expect(h.selection.ids, isEmpty);
      expect(t.widget<Checkbox>(_box(2)).onChanged, isNotNull);
    });

    testWidgets('tapping the row opens it; the checkbox only ticks', (t) async {
      final opened = <String>[];
      final h = await _loaded(t, opened: opened.add);
      await t.tap(_box(2));
      await t.pump();
      expect(h.selection.ids, {2});
      expect(opened, isEmpty);
      await t.tap(find.text('site3.com'));
      await t.pump();
      expect(opened, ['site3.com']);
      expect(h.selection.ids, {2});
    });

    testWidgets('a screen reader gets one reading per row and a checkbox '
        'label', (t) async {
      final semantics = t.ensureSemantics();
      final s = _Server()
        ..rows = [_row(1, name: 'alpha.com', expires: '2026-05-04T00:00:00Z')];
      await _loaded(t, server: s);
      expect(
        find.bySemanticsLabel(
          'alpha.com, status Active, Expires 2026-05-04, in 3 days',
        ),
        findsOneWidget,
      );
      expect(find.bySemanticsLabel('Select alpha.com'), findsOneWidget);
      semantics.dispose();
    });
  });

  group('search', () {
    testWidgets('toggle, field and clear are at least 48dp', (t) async {
      _small(t, scale: 2);
      await _loaded(t);
      final toggle = t.getSize(find.byTooltip('Search domains'));
      expect(toggle.width, greaterThanOrEqualTo(48));
      expect(toggle.height, greaterThanOrEqualTo(48));
      await t.tap(find.byTooltip('Search domains'));
      await t.pump();
      expect(find.byTooltip('Close search'), findsOneWidget);
      expect(
        t.getSize(find.byType(TextField)).height,
        greaterThanOrEqualTo(48),
      );
      await t.enterText(find.byType(TextField), 'site1');
      await t.pump();
      final clear = t.getSize(find.byTooltip('Clear search'));
      expect(clear.width, greaterThanOrEqualTo(48));
      expect(clear.height, greaterThanOrEqualTo(48));
      // Reachable on the narrow screen.
      final box = t.getRect(find.byType(TextField));
      expect(box.left, greaterThanOrEqualTo(0));
      expect(box.right, lessThanOrEqualTo(320));
      await t.pumpAndSettle(const Duration(seconds: 1));
    });

    testWidgets('an active search shows a badge while the field is closed', (
      t,
    ) async {
      final h = await _loaded(t);
      Badge badge() => t.widget<Badge>(
        find
            .descendant(
              of: find.byType(IconButton),
              matching: find.byType(Badge),
            )
            .first,
      );
      expect(badge().isLabelVisible, isFalse);
      await t.tap(find.byTooltip('Search domains'));
      await t.pump();
      await t.enterText(find.byType(TextField), 'site1');
      await t.pumpAndSettle(const Duration(seconds: 1));
      await t.tap(find.byTooltip('Close search'));
      await t.pump();
      expect(find.byType(TextField), findsNothing);
      expect(h.controller.query, 'site1'); // closing never clears
      expect(badge().isLabelVisible, isTrue);
    });

    testWidgets('keeps the 400ms debounce and the q parameter', (t) async {
      final s = _Server();
      await _loaded(t, server: s);
      final before = s.listRequests.length;
      await t.tap(find.byTooltip('Search domains'));
      await t.pump();
      await t.enterText(find.byType(TextField), 'si');
      await t.pump(const Duration(milliseconds: 200));
      await t.enterText(find.byType(TextField), '  site1 ');
      await t.pump(const Duration(milliseconds: 399));
      expect(s.listRequests.length, before);
      await t.pump(const Duration(milliseconds: 2));
      await t.pumpAndSettle();
      expect(s.listRequests.length, before + 1);
      final r = s.listRequests.last.url.queryParameters;
      expect(r['q'], 'site1');
      expect(r['page'], '1');
    });

    testWidgets('Clear search empties the box and drops q', (t) async {
      final s = _Server();
      await _loaded(t, server: s);
      await t.tap(find.byTooltip('Search domains'));
      await t.pump();
      await t.enterText(find.byType(TextField), 'site1');
      await t.pumpAndSettle(const Duration(seconds: 1));
      await t.tap(find.byTooltip('Clear search'));
      await t.pumpAndSettle(const Duration(seconds: 1));
      expect(t.widget<TextField>(find.byType(TextField)).controller!.text, '');
      expect(s.listRequests.last.url.queryParameters.containsKey('q'), isFalse);
    });

    testWidgets('an empty result offers Clear search through the same path', (
      t,
    ) async {
      final s = _Server();
      final h = await _loaded(t, server: s);
      await t.tap(find.byTooltip('Search domains'));
      await t.pump();
      await t.enterText(find.byType(TextField), 'zzz');
      await t.pumpAndSettle(const Duration(seconds: 1));
      expect(find.text('No domains match "zzz".'), findsOneWidget);
      expect(find.text('Show all domains'), findsNothing);
      await t.tap(find.text('Clear search'));
      await t.pumpAndSettle(const Duration(seconds: 1));
      expect(h.controller.query, '');
      expect(t.widget<TextField>(find.byType(TextField)).controller!.text, '');
      expect(find.text('site1.com'), findsOneWidget);
    });

    testWidgets('an empty filtered result keeps its wording and action', (
      t,
    ) async {
      final s = _Server()..rows = [];
      final h = await _loaded(t, server: s);
      h.controller.setExpiry(ExpiryFilter.expired);
      await t.pumpAndSettle();
      expect(find.text('No domains in "Expired".'), findsOneWidget);
      expect(find.text('Clear search'), findsNothing);
      await t.tap(find.text('Show all domains'));
      await t.pumpAndSettle();
      expect(h.controller.expiry, ExpiryFilter.all);
    });
  });

  group('filters and sort', () {
    testWidgets('every chip is at least 48dp tall and keeps its key', (
      t,
    ) async {
      _small(t, scale: 2);
      await _loaded(t);
      for (final f in ExpiryFilter.values) {
        expect(_chip(f), findsOneWidget);
        final size = t.getSize(_chip(f));
        expect(size.height, greaterThanOrEqualTo(48), reason: f.apiValue);
        expect(size.width, greaterThanOrEqualTo(48), reason: f.apiValue);
      }
    });

    testWidgets('the selected chip has a check mark, not only a colour', (
      t,
    ) async {
      final h = await _loaded(t);
      ChoiceChip chip(ExpiryFilter f) => t.widget<ChoiceChip>(_chip(f));
      expect(chip(ExpiryFilter.all).selected, isTrue);
      expect(chip(ExpiryFilter.all).showCheckmark, isTrue);
      expect(chip(ExpiryFilter.expired).selected, isFalse);
      await t.tap(_chip(ExpiryFilter.expired));
      await t.pumpAndSettle();
      expect(h.controller.expiry, ExpiryFilter.expired);
      expect(chip(ExpiryFilter.expired).selected, isTrue);
      expect(chip(ExpiryFilter.expired).side?.width, 2);
      expect(chip(ExpiryFilter.all).side?.width, 1);
    });

    testWidgets('a non-default sort shows a badge; the tooltip is kept', (
      t,
    ) async {
      final h = await _loaded(t);
      Badge badge() => t.widget<Badge>(
        find.descendant(
          of: find.byKey(const ValueKey('sort-menu')),
          matching: find.byType(Badge),
        ),
      );
      expect(find.byTooltip('Sort: Nearest expiry'), findsOneWidget);
      expect(badge().isLabelVisible, isFalse);
      h.controller.setSort(DomainSort.nameAsc);
      await t.pumpAndSettle();
      expect(find.byTooltip('Sort: Domain name'), findsOneWidget);
      expect(badge().isLabelVisible, isTrue);
      expect(
        t.getSize(find.byKey(const ValueKey('sort-menu'))).shortestSide,
        greaterThanOrEqualTo(48),
      );
    });
  });

  group('selection bar', () {
    testWidgets('Renew and the checkboxes are at least 48dp', (t) async {
      _small(t, scale: 2);
      final h = await _loaded(t);
      final renewKey = find.byKey(const ValueKey('renew-selected'));
      final renew = t.getSize(renewKey);
      expect(renew.height, greaterThanOrEqualTo(48));
      expect(renew.width, greaterThanOrEqualTo(48));
      final box = t.getSize(_box(1));
      expect(box.width, greaterThanOrEqualTo(48));
      expect(box.height, greaterThanOrEqualTo(48));
      expect(t.widget<FilledButton>(renewKey).onPressed, isNull);
      h.selection.set(1, true, name: 'site1.com');
      await t.pump();
      expect(find.text('1 selected'), findsOneWidget);
      await t.tap(renewKey);
      expect(h.renewTaps, 1);
    });

    testWidgets('selection survives search and filter; ticking sends no '
        'requests', (t) async {
      final s = _Server();
      final h = await _loaded(t, server: s);
      final before = s.requests.length;
      await t.tap(_box(1));
      await t.tap(_box(2));
      await t.pump();
      await t.tap(find.byTooltip('Search domains'));
      await t.pump();
      await t.tap(find.byTooltip('Close search'));
      await t.pump();
      expect(s.requests.length, before); // ticking and toggling send nothing
      expect(h.selection.ids, {1, 2});

      h.controller.setQuery('site4');
      await t.pumpAndSettle(const Duration(seconds: 1));
      h.controller.setExpiry(ExpiryFilter.expired);
      await t.pumpAndSettle();
      expect(h.selection.ids, {1, 2});
      expect(find.text('2 selected'), findsOneWidget);
    });
  });

  group('states', () {
    testWidgets('initial loading is described to a screen reader', (t) async {
      final semantics = t.ensureSemantics();
      final s = _Server()..gate = Completer<void>();
      final h = _Harness(s);
      addTearDown(h.dispose);
      await h.pump(t, settle: false);
      await t.pump();
      expect(find.byType(StateView), findsOneWidget);
      expect(find.text('Loading domains…'), findsOneWidget);
      expect(find.bySemanticsLabel('Loading domains…'), findsOneWidget);
      s.gate!.complete();
      await t.pumpAndSettle();
      semantics.dispose();
    });

    testWidgets('an initial error shows StateView and Retry reloads', (
      t,
    ) async {
      final s = _Server()..failList = true;
      final h = await _loaded(t, server: s);
      expect(find.byType(StateView), findsOneWidget);
      expect(h.controller.error, isNotNull);
      expect(find.text(h.controller.error!), findsOneWidget);
      final before = s.listRequests.length;
      s.failList = false;
      await t.tap(find.text('Retry'));
      await t.pumpAndSettle();
      expect(s.listRequests.length, before + 1);
      expect(find.text('site1.com'), findsOneWidget);
      expect(find.byType(StateView), findsNothing);
    });

    testWidgets('pull to refresh still works on an empty list', (t) async {
      final s = _Server()..rows = [];
      await _loaded(t, server: s);
      final before = s.listRequests.length;
      await t.fling(find.byType(StateView), const Offset(0, 400), 1000);
      await t.pumpAndSettle();
      expect(s.listRequests.length, greaterThan(before));
    });

    testWidgets('a failed refresh keeps the rows, with a banner and Retry', (
      t,
    ) async {
      final s = _Server();
      final h = await _loaded(t, server: s);
      s.failList = true;
      await h.controller.refresh();
      await t.pumpAndSettle();
      expect(find.text('site1.com'), findsOneWidget);
      expect(find.byType(OutcomeBanner), findsOneWidget);
      expect(find.text(h.controller.error!), findsOneWidget);
      s.failList = false;
      await t.tap(find.text('Retry'));
      await t.pumpAndSettle();
      expect(find.byType(OutcomeBanner), findsNothing);
      expect(find.text('site1.com'), findsOneWidget);
    });

    testWidgets('Load more, its spinner and its error banner', (t) async {
      _view(t, const Size(412, 3000), 1); // tall: the whole first page builds
      final s = _Server()..rows = [for (var i = 1; i <= 45; i++) _row(i)];
      final h = await _loaded(t, server: s);
      expect(h.controller.items.length, 20);
      expect(find.text('Load more'), findsOneWidget);

      s.gate = Completer<void>();
      await t.tap(find.text('Load more'));
      await t.pump();
      expect(find.byType(CircularProgressIndicator), findsOneWidget);
      s.gate!.complete();
      s.gate = null;
      await t.pumpAndSettle();
      expect(h.controller.items.length, 40);

      s.failList = true;
      await h.controller.loadMore();
      await t.pumpAndSettle();
      await t.drag(find.byType(Scrollable).last, const Offset(0, -3000));
      await t.pumpAndSettle();
      expect(find.byType(OutcomeBanner), findsOneWidget);
      expect(h.controller.items.length, 40);
    });
  });

  group('bulk sync panel layout', () {
    testWidgets('at 2.0x the controls drop to their own line, 48dp tall', (
      t,
    ) async {
      _small(t, scale: 2);
      await _loaded(t);
      final sync = _syncButton;
      final globe = find.byIcon(Icons.public);
      expect(sync, findsOneWidget);
      expect(t.getTopLeft(sync).dy, greaterThan(t.getBottomLeft(globe).dy));
      expect(t.getSize(sync).height, greaterThanOrEqualTo(48));
      expect(
        t.getSize(find.byTooltip('Search domains')).height,
        greaterThanOrEqualTo(48),
      );
      expect(find.text('USD 6,244.40'), findsOneWidget);
    });

    testWidgets('at 1.0x summary and controls share one row', (t) async {
      _view(t, const Size(412, 892), 1);
      await _loaded(t);
      final sync = _syncButton;
      final dy =
          (t.getCenter(sync).dy - t.getCenter(find.byIcon(Icons.public)).dy)
              .abs();
      expect(dy, lessThan(30));
      expect(t.getSize(sync).height, greaterThanOrEqualTo(48));
    });

    testWidgets('three-, four- and five-digit totals show in full', (t) async {
      for (final total in [123, 1234, 12345]) {
        _small(t, scale: 2);
        final s = _Server()..total = total;
        final h = _Harness(s);
        addTearDown(h.dispose);
        await h.pump(t);
        expect(find.text('$total'), findsOneWidget);
        expect(t.takeException(), isNull);
        await t.pumpWidget(const SizedBox());
      }
    });

    testWidgets('balance unavailable is a 48dp retry target', (t) async {
      _small(t, scale: 2);
      final s = _Server()..failBalance = true;
      await _loaded(t, server: s);
      final retry = find.ancestor(
        of: find.text('Balance unavailable'),
        matching: find.byType(InkWell),
      );
      expect(t.getSize(retry).height, greaterThanOrEqualTo(48));
    });
  });

  group('width', () {
    testWidgets('the list is capped at 640dp and centred on wide screens', (
      t,
    ) async {
      _view(t, const Size(1024, 768), 1);
      await _loaded(t);
      final list = find.byType(ListView).last;
      expect(t.getSize(list).width, 640);
      expect(t.getTopLeft(list).dx, 192);
    });

    testWidgets('phones use the whole width', (t) async {
      _view(t, const Size(360, 740), 1);
      await _loaded(t);
      expect(t.getSize(find.byType(ListView).last).width, 360);
    });
  });
}

// Compact layout refinement: density on real phone sizes.
void _compactTests() {
  group('compact layout', () {
    server12() => _Server()..rows = [for (var i = 1; i <= 12; i++) _row(i)];

    for (final size in const [
      Size(320, 568),
      Size(360, 740),
      Size(412, 892),
      Size(640, 900),
    ]) {
      testWidgets('count, balance, menu and search share one row at '
          '${size.width.toInt()}dp', (t) async {
        _view(t, size, 1);
        await _loaded(t);
        final ys = [
          t.getCenter(find.byIcon(Icons.public)).dy,
          t.getCenter(find.text('USD 6,244.40')).dy,
          t.getCenter(_syncButton).dy,
          t.getCenter(find.byTooltip('Search domains')).dy,
        ];
        for (final y in ys) {
          expect((y - ys.first).abs(), lessThan(6), reason: '$ys');
        }
        // The panel is one control row tall, not two.
        final panel = t.getSize(find.byType(Card).first).height;
        expect(panel, lessThanOrEqualTo(64));
        expect(t.takeException(), isNull);
      });
    }

    testWidgets('Renew bar is a single short row', (t) async {
      _view(t, const Size(360, 740), 1);
      await _loaded(t);
      final renew = t.getSize(find.byKey(const ValueKey('renew-selected')));
      expect(renew.height, lessThanOrEqualTo(48));
      expect(renew.height, greaterThanOrEqualTo(36));
      // Bar height = the button; no extra padding above or below it.
      final count = t.getCenter(find.byKey(const ValueKey('selected-count')));
      final button = t.getCenter(find.byKey(const ValueKey('renew-selected')));
      expect((count.dy - button.dy).abs(), lessThan(2));
    });
    // The test font is about twice as wide as a phone's Roboto, so density is
    // asserted at 640dp (the widest the list gets), where everything fits on
    // its natural lines; the narrow-phone cases are covered by the overflow
    // checks above and below.
    testWidgets('a card is two lines; the warning adds space only when it '
        'applies', (t) async {
      _view(t, const Size(640, 900), 1);
      final s = _Server()..rows = [_row(1), _row(2, missing: true), _row(3)];
      await _loaded(t, server: s);
      final cards = find.byType(Card);
      final normal = t.getSize(cards.at(1)).height;
      final missing = t.getSize(cards.at(2)).height;
      final after = t.getSize(cards.at(3)).height;
      expect(normal, lessThanOrEqualTo(64));
      expect(after, normal); // no reserved gap on ordinary rows
      expect(missing, lessThanOrEqualTo(normal + 20));
      expect(find.text('Missing from provider'), findsOneWidget);
      // The name and the relative badge share the first line.
      final nameY = t.getCenter(find.text('site1.com')).dy;
      final badgeY = t.getCenter(find.byType(StatusBadge).first).dy;
      expect((nameY - badgeY).abs(), lessThan(12));
    });

    testWidgets('five whole cards fit under the panel and filters at 640x740', (
      t,
    ) async {
      _view(t, const Size(640, 740), 1);
      await _loaded(t, server: server12());
      final list = t.getRect(find.byType(ListView).last);
      var whole = 0;
      for (final c in find.byType(Card).evaluate().skip(1)) {
        final r = t.getRect(find.byWidget(c.widget));
        if (r.top >= list.top && r.bottom <= list.bottom) whole++;
      }
      expect(whole, greaterThanOrEqualTo(5));
    });

    for (final size in const [
      Size(320, 568),
      Size(360, 740),
      Size(412, 892),
      Size(640, 900),
    ]) {
      testWidgets('2.0x text: no overflow, long values wrap, at '
          '${size.width.toInt()}dp', (t) async {
        _view(t, size, 2);
        final s = _Server()
          ..total = 12345
          ..rows = [
            _row(1, name: _longName, status: _longStatus, missing: true),
            _row(2),
          ];
        final h = await _loaded(t, server: s);
        h.selection.set(2, true, name: 'site2.com');
        await t.pump();
        expect(t.takeException(), isNull);
        expect(find.text('12345'), findsOneWidget);
        await t.drag(find.byType(Scrollable).last, const Offset(0, -500));
        await t.pump();
        expect(t.takeException(), isNull);
      });
    }
  });
}
