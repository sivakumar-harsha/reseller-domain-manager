import 'package:flutter/widgets.dart';

/// Motion tokens. Motion is limited to press feedback and result/status
/// transitions; frequent surfaces (lists, filters, tabs, scrolling) do not
/// animate. Only transform and opacity are ever animated.
class AppMotion {
  AppMotion._();

  /// Press feedback (100-160ms).
  static const Duration press = Duration(milliseconds: 120);

  /// A status / result change settling in (under 300ms).
  static const Duration settle = Duration(milliseconds: 200);

  /// Strong ease-out for UI.
  static const Curve easeOut = Cubic(0.23, 1, 0.32, 1);

  /// Scale applied while a control is pressed.
  static const double pressedScale = 0.97;

  /// Scale an entering element starts from (never 0).
  static const double enterScale = 0.97;

  /// True when the user (or system) asked for reduced motion.
  static bool reduced(BuildContext context) =>
      MediaQuery.maybeDisableAnimationsOf(context) ?? false;

  /// [d], or zero when reduced motion is on.
  static Duration of(BuildContext context, Duration d) =>
      reduced(context) ? Duration.zero : d;
}
