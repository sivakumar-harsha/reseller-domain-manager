import 'package:flutter/material.dart';

/// Brand palette (light only). Screens should read colours from
/// `Theme.of(context)` (ColorScheme or [StatusColors]) rather than using these
/// directly.
///
/// The accent (indigo) is deliberately the one hue the status ladder never
/// uses (red / orange / amber / green / grey), so it can never be mistaken for
/// urgency.
class AppColors {
  AppColors._();

  static const Color indigo = Color(0xFF3F4A9E);

  static const Color background = Color(0xFFF6F7FB);
  static const Color surface = Color(0xFFFFFFFF);
  static const Color outline = Color(0xFFD9DCE6);
  static const Color text = Color(0xFF1B1F2E);
  static const Color textMuted = Color(0xFF596078);

  static const Color error = Color(0xFFB3261E);
}

/// Semantic status colours (success / warning / neutral) exposed through the
/// theme: `Theme.of(context).extension<StatusColors>()!`. Error uses the
/// ColorScheme error roles.
@immutable
class StatusColors extends ThemeExtension<StatusColors> {
  const StatusColors({
    required this.success,
    required this.onSuccess,
    required this.successContainer,
    required this.warning,
    required this.onWarning,
    required this.warningContainer,
    required this.neutral,
    required this.neutralContainer,
    required this.caution,
  });

  /// Amber: a softer alert than [warning] (orange), e.g. expiry within 30 days.
  final Color caution;
  final Color success;
  final Color onSuccess;
  final Color successContainer;
  final Color warning;
  final Color onWarning;
  final Color warningContainer;
  final Color neutral;
  final Color neutralContainer;

  static const StatusColors light = StatusColors(
    success: Color(0xFF166C3F),
    onSuccess: Color(0xFFFFFFFF),
    successContainer: Color(0xFFDDF3E6),
    warning: Color(0xFF9A5205),
    onWarning: Color(0xFFFFFFFF),
    warningContainer: Color(0xFFFCEBD3),
    neutral: Color(0xFF596078),
    neutralContainer: Color(0xFFE9EBF2),
    caution: Color(0xFF7A5F00),
  );

  @override
  StatusColors copyWith({
    Color? success,
    Color? onSuccess,
    Color? successContainer,
    Color? warning,
    Color? onWarning,
    Color? warningContainer,
    Color? neutral,
    Color? neutralContainer,
    Color? caution,
  }) => StatusColors(
    caution: caution ?? this.caution,
    success: success ?? this.success,
    onSuccess: onSuccess ?? this.onSuccess,
    successContainer: successContainer ?? this.successContainer,
    warning: warning ?? this.warning,
    onWarning: onWarning ?? this.onWarning,
    warningContainer: warningContainer ?? this.warningContainer,
    neutral: neutral ?? this.neutral,
    neutralContainer: neutralContainer ?? this.neutralContainer,
  );

  @override
  StatusColors lerp(ThemeExtension<StatusColors>? other, double t) {
    if (other is! StatusColors) return this;
    return StatusColors(
      caution: Color.lerp(caution, other.caution, t)!,
      success: Color.lerp(success, other.success, t)!,
      onSuccess: Color.lerp(onSuccess, other.onSuccess, t)!,
      successContainer: Color.lerp(
        successContainer,
        other.successContainer,
        t,
      )!,
      warning: Color.lerp(warning, other.warning, t)!,
      onWarning: Color.lerp(onWarning, other.onWarning, t)!,
      warningContainer: Color.lerp(
        warningContainer,
        other.warningContainer,
        t,
      )!,
      neutral: Color.lerp(neutral, other.neutral, t)!,
      neutralContainer: Color.lerp(
        neutralContainer,
        other.neutralContainer,
        t,
      )!,
    );
  }
}
