import 'package:flutter/material.dart';

import '../auth/auth_controller.dart';
import '../domains/domain_controller.dart';
import '../domains/domain.dart';
import 'domain_detail_screen.dart';
import 'domain_list_screen.dart';

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key, required this.auth, this.domains});

  final AuthController auth;

  /// Injected by tests; the app builds its own from the auth controller's API.
  final DomainController? domains;

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  late final DomainController _domains =
      widget.domains ?? DomainController(api: widget.auth.api);

  @override
  void initState() {
    super.initState();
    _domains.load();
  }

  @override
  void dispose() {
    _domains.dispose();
    super.dispose();
  }

  void _open(Domain domain) {
    Navigator.of(context).push(MaterialPageRoute<void>(
      builder: (_) => DomainDetailScreen(
        api: widget.auth.api,
        auth: widget.auth,
        domainId: domain.id,
        title: domain.domainName,
      ),
    ));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Domains'),
        actions: [
          IconButton(
            tooltip: 'Log out',
            icon: const Icon(Icons.logout),
            onPressed: widget.auth.logout,
          ),
        ],
      ),
      body: DomainListScreen(controller: _domains, onOpen: _open),
    );
  }
}
