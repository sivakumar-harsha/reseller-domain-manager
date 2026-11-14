import 'package:flutter/widgets.dart';

import '../theme/app_motion.dart';

/// Press feedback: scales the child to 0.97 while a pointer is down. It only
/// observes pointers (it never claims the gesture), so the child's own tap
/// handling is unchanged. Transform only; instant under reduced motion.
class Pressable extends StatefulWidget {
  const Pressable({super.key, required this.child, this.enabled = true});

  final Widget child;
  final bool enabled;

  @override
  State<Pressable> createState() => _PressableState();
}

class _PressableState extends State<Pressable> {
  bool _down = false;

  void _set(bool v) {
    if (_down != v && mounted) setState(() => _down = v);
  }

  @override
  Widget build(BuildContext context) {
    return Listener(
      onPointerDown: widget.enabled ? (_) => _set(true) : null,
      onPointerUp: (_) => _set(false),
      onPointerCancel: (_) => _set(false),
      child: AnimatedScale(
        scale: _down && widget.enabled ? AppMotion.pressedScale : 1,
        duration: AppMotion.of(context, AppMotion.press),
        curve: AppMotion.easeOut,
        child: widget.child,
      ),
    );
  }
}
