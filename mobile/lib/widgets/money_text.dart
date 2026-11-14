import 'package:flutter/material.dart';

import '../theme/app_typography.dart';

/// Displays an already-formatted amount (or count) with tabular figures at the
/// strongest weight. It never formats, rounds or converts: callers pass the
/// string produced by the existing formatters, so no figure can change here.
class MoneyText extends StatelessWidget {
  const MoneyText(this.text, {super.key, this.style, this.textAlign});

  final String text;

  /// Base style; defaults to titleMedium. Tabular figures and w700 are applied.
  final TextStyle? style;
  final TextAlign? textAlign;

  @override
  Widget build(BuildContext context) {
    final base = style ?? Theme.of(context).textTheme.titleMedium!;
    return Text(
      text,
      textAlign: textAlign,
      style: AppTypography.tabular(base.copyWith(fontWeight: FontWeight.w700)),
    );
  }
}
