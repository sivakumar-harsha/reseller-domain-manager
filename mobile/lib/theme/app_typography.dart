import 'package:flutter/material.dart';

/// Typography scale layered on the Material 3 text theme (system font).
///
/// Role mapping:
///  - Screen title     -> titleLarge (22 / w600)
///  - Section heading  -> titleMedium (16 / w600)
///  - Card title       -> titleSmall (14 / w600)
///  - Domain name      -> [domainName] (16 / w600)
///  - Primary value    -> headlineSmall (24 / w700)
///  - Secondary label  -> labelLarge (14 / w600) / bodyMedium (14)
///  - Supporting info  -> bodySmall (12, muted)
///  - Error / status   -> [statusMessage]
class AppTypography {
  AppTypography._();

  static TextTheme textTheme(TextTheme base, Color text, Color muted) {
    return base
        .copyWith(
          headlineMedium: base.headlineMedium?.copyWith(
            fontSize: 28,
            fontWeight: FontWeight.w700,
          ),
          headlineSmall: base.headlineSmall?.copyWith(
            fontSize: 24,
            fontWeight: FontWeight.w700,
            height: 1.25,
          ),
          titleLarge: base.titleLarge?.copyWith(
            fontSize: 22,
            fontWeight: FontWeight.w600,
            height: 1.27,
          ),
          titleMedium: base.titleMedium?.copyWith(
            fontSize: 16,
            fontWeight: FontWeight.w600,
            height: 1.5,
          ),
          titleSmall: base.titleSmall?.copyWith(
            fontSize: 14,
            fontWeight: FontWeight.w600,
            height: 1.43,
          ),
          bodyLarge: base.bodyLarge?.copyWith(fontSize: 16, height: 1.5),
          bodyMedium: base.bodyMedium?.copyWith(fontSize: 14, height: 1.43),
          bodySmall: base.bodySmall?.copyWith(fontSize: 12, height: 1.33),
          labelLarge: base.labelLarge?.copyWith(
            fontSize: 14,
            fontWeight: FontWeight.w600,
          ),
          labelMedium: base.labelMedium?.copyWith(
            fontSize: 12,
            fontWeight: FontWeight.w500,
          ),
          labelSmall: base.labelSmall?.copyWith(
            fontSize: 11,
            fontWeight: FontWeight.w500,
          ),
        )
        .apply(bodyColor: text, displayColor: text)
        .copyWith(
          bodySmall: base.bodySmall?.copyWith(
            fontSize: 12,
            height: 1.33,
            color: muted,
          ),
          labelMedium: base.labelMedium?.copyWith(
            fontSize: 12,
            fontWeight: FontWeight.w500,
            color: muted,
          ),
        );
  }

  /// Tabular (fixed-width) figures so amounts and day counts align and do not
  /// jitter. Presentation only: it never changes the text itself.
  static TextStyle tabular(TextStyle style) => style.copyWith(
    fontFeatures: [...?style.fontFeatures, const FontFeature.tabularFigures()],
  );

  /// Domain names: semi-bold, slightly open tracking.
  static TextStyle domainName(BuildContext context) =>
      Theme.of(context).textTheme.titleMedium!.copyWith(letterSpacing: 0.1);

  /// Error / status messages.
  static TextStyle statusMessage(BuildContext context, {bool error = true}) {
    final theme = Theme.of(context);
    return theme.textTheme.bodyMedium!.copyWith(
      color: error ? theme.colorScheme.error : theme.colorScheme.onSurface,
      fontWeight: FontWeight.w500,
    );
  }
}
