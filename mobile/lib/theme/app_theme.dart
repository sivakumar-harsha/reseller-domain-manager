import 'package:flutter/material.dart';

import 'app_colors.dart';
import 'app_spacing.dart';
import 'app_typography.dart';

/// Central Material 3 theme. The app is light-only: there is no dark theme, no
/// toggle and the system brightness is never followed (see `app.dart`).
class AppTheme {
  AppTheme._();

  /// Minimum touch target height for buttons (48dp).
  static const double minTouchHeight = 48;

  static ThemeData get light => _build();

  static ThemeData _build() {
    const background = AppColors.background;
    const surface = AppColors.surface;
    const outline = AppColors.outline;
    const text = AppColors.text;
    const muted = AppColors.textMuted;

    final scheme =
        ColorScheme.fromSeed(
          seedColor: AppColors.indigo,
          brightness: Brightness.light,
        ).copyWith(
          primary: AppColors.indigo,
          onPrimary: Colors.white,
          primaryContainer: const Color(0xFFE0E3FA),
          onPrimaryContainer: const Color(0xFF1A1F5C),
          secondaryContainer: const Color(0xFFE4E6F4),
          onSecondaryContainer: text,
          surface: surface,
          onSurface: text,
          onSurfaceVariant: muted,
          surfaceContainerLowest: surface,
          surfaceContainerLow: surface,
          surfaceContainer: background,
          surfaceContainerHigh: const Color(0xFFEEF0F7),
          surfaceContainerHighest: const Color(0xFFE6E8F1),
          outline: muted,
          outlineVariant: outline,
          error: AppColors.error,
          onError: Colors.white,
          errorContainer: const Color(0xFFF9DEDC),
          onErrorContainer: const Color(0xFF410E0B),
        );

    final textTheme = AppTypography.textTheme(
      ThemeData(brightness: Brightness.light).textTheme,
      text,
      muted,
    );
    const border = BorderSide(color: outline);
    const buttonShape = RoundedRectangleBorder(borderRadius: AppRadius.control);
    const buttonPadding = EdgeInsets.symmetric(
      horizontal: AppSpacing.lg,
      vertical: AppSpacing.component,
    );
    const buttonMinimum = Size(64, minTouchHeight);

    OutlineInputBorder inputBorder(Color c, [double w = 1]) =>
        OutlineInputBorder(
          borderRadius: AppRadius.control,
          borderSide: BorderSide(color: c, width: w),
        );

    return ThemeData(
      useMaterial3: true,
      brightness: Brightness.light,
      colorScheme: scheme,
      scaffoldBackgroundColor: background,
      textTheme: textTheme,
      extensions: const <ThemeExtension<dynamic>>[StatusColors.light],
      dividerTheme: const DividerThemeData(
        color: outline,
        space: 1,
        thickness: 1,
      ),
      appBarTheme: AppBarTheme(
        backgroundColor: surface,
        foregroundColor: text,
        elevation: 0,
        scrolledUnderElevation: 0,
        surfaceTintColor: Colors.transparent,
        centerTitle: false,
        titleTextStyle: textTheme.titleLarge,
        shape: const Border(bottom: border),
      ),
      cardTheme: const CardThemeData(
        color: surface,
        elevation: 0,
        margin: EdgeInsets.zero,
        surfaceTintColor: Colors.transparent,
        shape: RoundedRectangleBorder(
          borderRadius: AppRadius.card,
          side: border,
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: buttonMinimum,
          shape: buttonShape,
          padding: buttonPadding,
          textStyle: textTheme.labelLarge,
        ),
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          minimumSize: buttonMinimum,
          elevation: 0,
          backgroundColor: scheme.primary,
          foregroundColor: scheme.onPrimary,
          shape: buttonShape,
          padding: buttonPadding,
          textStyle: textTheme.labelLarge,
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          minimumSize: buttonMinimum,
          shape: buttonShape,
          padding: buttonPadding,
          side: const BorderSide(color: outline),
          textStyle: textTheme.labelLarge,
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          minimumSize: buttonMinimum,
          shape: buttonShape,
          textStyle: textTheme.labelLarge,
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: surface,
        contentPadding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.md,
          vertical: AppSpacing.component,
        ),
        border: inputBorder(outline),
        enabledBorder: inputBorder(outline),
        focusedBorder: inputBorder(scheme.primary, 2),
        errorBorder: inputBorder(scheme.error),
        focusedErrorBorder: inputBorder(scheme.error, 2),
        labelStyle: textTheme.bodyMedium?.copyWith(color: muted),
        hintStyle: textTheme.bodyMedium?.copyWith(color: muted),
        errorStyle: textTheme.bodySmall?.copyWith(color: scheme.error),
      ),
      chipTheme: ChipThemeData(
        backgroundColor: surface,
        selectedColor: scheme.primaryContainer,
        side: border,
        shape: const RoundedRectangleBorder(borderRadius: AppRadius.control),
        labelStyle: textTheme.labelLarge?.copyWith(fontWeight: FontWeight.w500),
        checkmarkColor: scheme.onPrimaryContainer,
        padding: const EdgeInsets.symmetric(horizontal: AppSpacing.xs),
        elevation: 0,
        pressElevation: 0,
      ),
      drawerTheme: const DrawerThemeData(
        backgroundColor: surface,
        surfaceTintColor: Colors.transparent,
        elevation: 1,
      ),
      navigationDrawerTheme: NavigationDrawerThemeData(
        backgroundColor: surface,
        surfaceTintColor: Colors.transparent,
        indicatorColor: scheme.primaryContainer,
        indicatorShape: const RoundedRectangleBorder(
          borderRadius: AppRadius.control,
        ),
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: surface,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        indicatorColor: scheme.primaryContainer,
        labelTextStyle: WidgetStatePropertyAll(textTheme.labelMedium),
      ),
      listTileTheme: ListTileThemeData(
        iconColor: muted,
        textColor: text,
        titleTextStyle: textTheme.bodyLarge,
        subtitleTextStyle: textTheme.bodySmall,
        shape: const RoundedRectangleBorder(borderRadius: AppRadius.control),
      ),
      dialogTheme: DialogThemeData(
        backgroundColor: surface,
        surfaceTintColor: Colors.transparent,
        elevation: 3,
        shape: const RoundedRectangleBorder(borderRadius: AppRadius.card),
        titleTextStyle: textTheme.titleLarge,
        contentTextStyle: textTheme.bodyMedium,
      ),
      bottomSheetTheme: const BottomSheetThemeData(
        backgroundColor: surface,
        surfaceTintColor: Colors.transparent,
      ),
      snackBarTheme: const SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: AppRadius.control),
      ),
      progressIndicatorTheme: ProgressIndicatorThemeData(
        color: scheme.primary,
        linearTrackColor: scheme.surfaceContainerHighest,
        circularTrackColor: Colors.transparent,
      ),
    );
  }
}
