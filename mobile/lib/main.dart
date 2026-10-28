import 'package:flutter/material.dart';

import 'app.dart';
import 'auth/auth_controller.dart';
import 'auth/token_storage.dart';
import 'core/api_client.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  final auth = AuthController(api: ApiClient(), storage: SecureTokenStorage())
    ..restore();
  runApp(App(auth: auth));
}
