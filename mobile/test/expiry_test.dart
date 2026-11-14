import 'package:domain_manager/domains/domain.dart';
import 'package:domain_manager/domains/expiry.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  // A fixed local "now" in the middle of the day.
  final now = DateTime(2026, 10, 30, 14, 30);

  // Expiry instants are UTC; build them from a local calendar day and time.
  DateTime expiry(int dayOffset, {int hour = 12, int minute = 0}) =>
      DateTime(2026, 10, 30 + dayOffset, hour, minute).toUtc();

  ExpiryInfo info(DateTime? e) => ExpiryInfo.of(e, now);

  group('ExpiryInfo status and days', () {
    test('missing expiry is unknown with no day count', () {
      final i = info(null);
      expect(i.status, ExpiryStatus.unknown);
      expect(i.daysRemaining, isNull);
      expect(i.isExpired, isFalse);
      expect(i.isActive, isFalse);
      expect(i.expiresWithin(90), isFalse);
    });

    test('expired yesterday', () {
      final i = info(expiry(-1));
      expect(i.status, ExpiryStatus.expired);
      expect(i.daysRemaining, -1);
      expect(i.isExpired, isTrue);
    });

    test('expires today, earlier or later than now, is not expired', () {
      for (final e in [
        expiry(0, hour: 0, minute: 1),
        expiry(0, hour: 9),
        expiry(0, hour: 23, minute: 59),
      ]) {
        final i = info(e);
        expect(i.status, ExpiryStatus.today);
        expect(i.daysRemaining, 0);
        expect(i.isExpired, isFalse);
      }
    });

    test('expires tomorrow, even a minute after midnight', () {
      final i = info(expiry(1, hour: 0, minute: 1));
      expect(i.status, ExpiryStatus.tomorrow);
      expect(i.daysRemaining, 1);
    });

    test('upcoming boundaries are exact calendar days', () {
      for (final d in [2, 7, 30, 60, 90]) {
        final i = info(expiry(d));
        expect(i.status, ExpiryStatus.upcoming);
        expect(i.daysRemaining, d);
      }
    });

    test('expiresWithin includes the boundary day only', () {
      expect(info(expiry(7)).expiresWithin(7), isTrue);
      expect(info(expiry(8)).expiresWithin(7), isFalse);
      expect(info(expiry(30)).expiresWithin(30), isTrue);
      expect(info(expiry(31)).expiresWithin(30), isFalse);
      expect(info(expiry(60)).expiresWithin(60), isTrue);
      expect(info(expiry(90)).expiresWithin(90), isTrue);
      expect(info(expiry(91)).expiresWithin(90), isFalse);
      expect(info(expiry(0)).expiresWithin(7), isTrue);
      expect(info(expiry(-1)).expiresWithin(7), isFalse);
    });

    test('result does not depend on the time of day of now', () {
      final e = expiry(7);
      for (final h in [0, 6, 12, 18, 23]) {
        expect(
          ExpiryInfo.of(e, DateTime(2026, 10, 30, h, 59)).daysRemaining,
          7,
        );
      }
    });

    test('counts calendar days across a month and year end', () {
      final i = ExpiryInfo.of(
        DateTime(2027, 1, 5, 8).toUtc(),
        DateTime(2026, 12, 30, 22),
      );
      expect(i.daysRemaining, 6);
    });
  });

  group('describeExpiry uses the shared calculation', () {
    test('wording per status', () {
      expect(describeExpiry(null, now), 'Expiry unknown');
      expect(describeExpiry(expiry(0, hour: 23), now), contains('(today)'));
      expect(describeExpiry(expiry(0, hour: 23), now), startsWith('Expires'));
      expect(describeExpiry(expiry(1), now), contains('(tomorrow)'));
      expect(describeExpiry(expiry(90), now), contains('(in 90 days)'));
      expect(describeExpiry(expiry(-1), now), startsWith('Expired'));
      expect(describeExpiry(expiry(-1), now), contains('(yesterday)'));
      expect(describeExpiry(expiry(-9), now), contains('(9 days ago)'));
    });
  });
}
