import 'package:flutter/widgets.dart';

/// Spacing and shape constants (4/8 dp grid).
class AppSpacing {
  AppSpacing._();

  static const double xs = 4;
  static const double sm = 8;
  static const double md = 16;
  static const double lg = 24;
  static const double xl = 32;

  /// Space between sibling components.
  static const double component = 12;

  static const EdgeInsets screenPadding = EdgeInsets.all(md);
  static const EdgeInsets cardPadding = EdgeInsets.all(md);
}

class AppRadius {
  AppRadius._();

  static const double sm = 8;
  static const double md = 12;
  static const double pill = 999;

  static const BorderRadius card = BorderRadius.all(Radius.circular(md));
  static const BorderRadius control = BorderRadius.all(Radius.circular(sm));
}
