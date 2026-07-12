import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../api_client.dart';
import '../auth.dart';

/// The landing point for a B2B launch link (`/l/<ticket>`). Exchanges the
/// one-time ticket for a token, then drops the learner into the deep-linked
/// course, or the dashboard if the launch named none.
class LaunchScreen extends StatefulWidget {
  const LaunchScreen({super.key, required this.ticket});

  final String ticket;

  @override
  State<LaunchScreen> createState() => _LaunchScreenState();
}

class _LaunchScreenState extends State<LaunchScreen> {
  String? _error;

  @override
  void initState() {
    super.initState();
    _exchange();
  }

  Future<void> _exchange() async {
    try {
      final courseId = await context.read<AuthState>().completeLaunch(widget.ticket);
      if (!mounted) return;
      if (courseId != null) {
        // Replace so Back doesn't return to the (now-spent) launch ticket.
        context.pushReplacement('/courses/$courseId');
      } else {
        context.go('/');
      }
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } catch (_) {
      if (mounted) setState(() => _error = 'Could not open this link.');
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Center(
        child: _error == null
            ? const Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  CircularProgressIndicator(),
                  SizedBox(height: 16),
                  Text('Signing you in…'),
                ],
              )
            : Padding(
                padding: const EdgeInsets.all(24),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(Icons.link_off_rounded, size: 40, color: Theme.of(context).colorScheme.error),
                    const SizedBox(height: 12),
                    Text(_error!, textAlign: TextAlign.center),
                    const SizedBox(height: 16),
                    FilledButton(onPressed: () => context.go('/login'), child: const Text('Go to sign in')),
                  ],
                ),
              ),
      ),
    );
  }
}
