import 'package:flutter/material.dart';

import 'app.dart';
import 'auth/auth_controller.dart';
import 'auth/token_storage.dart';
import 'core/api_client.dart';
import 'domains/auto_refresh.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  final api = ApiClient();
  final auth = AuthController(api: api, storage: SecureTokenStorage())
    ..restore();
  runApp(
    App(
      auth: auth,
      autoRefresh: AutoRefreshController(
        api: api,
        store: SecureRefreshStampStore(),
      ),
    ),
  );
}
