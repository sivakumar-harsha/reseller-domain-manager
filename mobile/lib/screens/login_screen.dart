import 'package:flutter/material.dart';

import '../auth/auth_controller.dart';
import '../theme/app_spacing.dart';
import '../widgets/outcome_banner.dart';
import '../widgets/status_badge.dart';

/// App mark and name, shared by the login and startup screens.
class AuthBrand extends StatelessWidget {
  const AuthBrand({super.key, this.tagline});

  /// Optional line under the name.
  final String? tagline;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        DecoratedBox(
          decoration: BoxDecoration(
            color: scheme.primaryContainer,
            borderRadius: AppRadius.card,
          ),
          child: Padding(
            padding: const EdgeInsets.all(AppSpacing.component),
            child: Icon(
              Icons.dns_outlined,
              size: 32,
              color: scheme.onPrimaryContainer,
            ),
          ),
        ),
        const SizedBox(height: AppSpacing.md),
        Semantics(
          header: true,
          child: Text(
            'Domain Manager',
            style: theme.textTheme.headlineMedium,
            textAlign: TextAlign.center,
          ),
        ),
        if (tagline != null) ...[
          const SizedBox(height: AppSpacing.xs),
          Text(
            tagline!,
            style: theme.textTheme.bodyMedium?.copyWith(
              color: scheme.onSurfaceVariant,
            ),
            textAlign: TextAlign.center,
          ),
        ],
      ],
    );
  }
}

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
  bool _obscure = true;

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
        child: LayoutBuilder(
          builder: (context, viewport) => SingleChildScrollView(
            keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
            padding: const EdgeInsets.all(AppSpacing.lg),
            child: ConstrainedBox(
              // Centred when there is room; scrolls when the keyboard or a
              // large font takes it away.
              constraints: BoxConstraints(
                minHeight: viewport.maxHeight - 2 * AppSpacing.lg,
              ),
              child: Center(
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 400),
                  child: ListenableBuilder(
                    listenable: widget.auth,
                    builder: (context, _) => _form(context, widget.auth),
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _form(BuildContext context, AuthController auth) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const AuthBrand(tagline: 'Sign in to manage your domains.'),
        const SizedBox(height: AppSpacing.lg),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(AppSpacing.md),
            child: AutofillGroup(
              child: Form(
                key: _formKey,
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    TextFormField(
                      controller: _email,
                      keyboardType: TextInputType.emailAddress,
                      textInputAction: TextInputAction.next,
                      autocorrect: false,
                      enableSuggestions: false,
                      autofillHints: const [AutofillHints.email],
                      decoration: const InputDecoration(
                        labelText: 'Email',
                        prefixIcon: Icon(Icons.mail_outline),
                      ),
                      validator: (v) => (v == null || v.trim().isEmpty)
                          ? 'Enter your email'
                          : null,
                    ),
                    const SizedBox(height: AppSpacing.md),
                    TextFormField(
                      controller: _password,
                      obscureText: _obscure,
                      textInputAction: TextInputAction.done,
                      autocorrect: false,
                      enableSuggestions: false,
                      autofillHints: const [AutofillHints.password],
                      decoration: InputDecoration(
                        labelText: 'Password',
                        prefixIcon: const Icon(Icons.lock_outline),
                        suffixIcon: IconButton(
                          key: const ValueKey('toggle-password'),
                          tooltip: _obscure ? 'Show password' : 'Hide password',
                          icon: Icon(
                            _obscure
                                ? Icons.visibility_outlined
                                : Icons.visibility_off_outlined,
                          ),
                          onPressed: () => setState(() => _obscure = !_obscure),
                        ),
                      ),
                      validator: (v) => (v == null || v.isEmpty)
                          ? 'Enter your password'
                          : null,
                      onFieldSubmitted: (_) => _submit(),
                    ),
                    if (auth.errorMessage != null) ...[
                      const SizedBox(height: AppSpacing.md),
                      OutcomeBanner(
                        key: const ValueKey('login-error'),
                        tone: BadgeTone.danger,
                        title: auth.errorMessage!,
                      ),
                    ],
                    const SizedBox(height: AppSpacing.lg),
                    FilledButton(
                      onPressed: auth.busy ? null : _submit,
                      child: auth.busy
                          ? SizedBox(
                              height: 20,
                              width: 20,
                              child: CircularProgressIndicator(
                                strokeWidth: 2,
                                semanticsLabel: 'Signing in',
                                // The default (primary) is invisible on the
                                // primary-coloured button.
                                color: scheme.onPrimary,
                              ),
                            )
                          : const Text('Sign in'),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ],
    );
  }
}
