import 'package:flutter/material.dart';

import '../domains/domain_controller.dart';
import '../domains/expiry.dart';
import '../domains/refresh.dart';
import '../widgets/refresh_status.dart';
import '../theme/app_spacing.dart';
import 'expiry_dashboard.dart';

/// Landing screen: the expiry overview. Reads the shared controller's summary;
/// pull-to-refresh runs [onRefresh] (list and summary together).
class DashboardScreen extends StatelessWidget {
  const DashboardScreen({
    super.key,
    required this.controller,
    required this.onRefresh,
    required this.onSelected,
    this.refresh,
  });

  final DomainController controller;
  final Future<void> Function() onRefresh;
  final void Function(ExpiryFilter filter) onSelected;

  /// The unified Refresh; its status line is shown above the overview when set.
  final RefreshController? refresh;

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: onRefresh,
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: AppSpacing.screenPadding,
        children: [
          if (refresh != null) RefreshStatusLine(controller: refresh!),
          // Centred and capped so the overview stays readable on wide screens.
          Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 640),
              child: ExpiryDashboard(
                controller: controller,
                onSelected: onSelected,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
