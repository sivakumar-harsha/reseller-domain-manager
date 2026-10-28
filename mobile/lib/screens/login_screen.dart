import 'package:flutter/material.dart';

import '../auth/auth_controller.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _email = TextEditingController();
  final _password = TextEditingController();

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    await widget.auth.login(_email.text, _password.text);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 400),
              child: ListenableBuilder(
                listenable: widget.auth,
                builder: (context, _) {
                  final auth = widget.auth;
                  return Form(
                    key: _formKey,
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        Text('Domain Manager',
                            style: Theme.of(context).textTheme.headlineMedium,
                            textAlign: TextAlign.center),
                        const SizedBox(height: 32),
                        TextFormField(
                          controller: _email,
                          keyboardType: TextInputType.emailAddress,
                          autofillHints: const [AutofillHints.email],
                          decoration: const InputDecoration(
                              labelText: 'Email', border: OutlineInputBorder()),
                          validator: (v) => (v == null || v.trim().isEmpty)
                              ? 'Enter your email'
                              : null,
                        ),
                        const SizedBox(height: 16),
                        TextFormField(
                          controller: _password,
                          obscureText: true,
                          autofillHints: const [AutofillHints.password],
                          decoration: const InputDecoration(
                              labelText: 'Password',
                              border: OutlineInputBorder()),
                          validator: (v) => (v == null || v.isEmpty)
                              ? 'Enter your password'
                              : null,
                          onFieldSubmitted: (_) => _submit(),
                        ),
                        if (auth.errorMessage != null) ...[
                          const SizedBox(height: 16),
                          Text(auth.errorMessage!,
                              style: TextStyle(
                                  color: Theme.of(context).colorScheme.error)),
                        ],
                        const SizedBox(height: 24),
                        FilledButton(
                          onPressed: auth.busy ? null : _submit,
                          child: auth.busy
                              ? const SizedBox(
                                  height: 20,
                                  width: 20,
                                  child:
                                      CircularProgressIndicator(strokeWidth: 2))
                              : const Text('Sign in'),
                        ),
                      ],
                    ),
                  );
                },
              ),
            ),
          ),
        ),
      ),
    );
  }
}
