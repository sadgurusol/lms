import 'dart:async';

import 'package:app_links/app_links.dart';
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import 'api_client.dart';
import 'auth.dart';
import 'theme.dart';
import 'screens/assessments_screen.dart';
import 'screens/attempt_screen.dart';
import 'screens/course_screen.dart';
import 'screens/home_shell.dart';
import 'screens/launch_screen.dart';
import 'screens/login_screen.dart';
import 'screens/store_screen.dart';
import 'screens/tutor_screen.dart';

void main() {
  // Required before any plugin/platform-channel use (secure storage in restore()).
  WidgetsFlutterBinding.ensureInitialized();

  final api = ApiClient();
  final auth = AuthState(api)..restore();

  runApp(
    MultiProvider(
      providers: [
        Provider<ApiClient>.value(value: api),
        ChangeNotifierProvider<AuthState>.value(value: auth),
      ],
      child: const LearnerApp(),
    ),
  );
}

class LearnerApp extends StatefulWidget {
  const LearnerApp({super.key});

  @override
  State<LearnerApp> createState() => _LearnerAppState();
}

class _LearnerAppState extends State<LearnerApp> {
  late final GoRouter _router;
  final _appLinks = AppLinks();
  StreamSubscription<Uri>? _linkSub;

  @override
  void initState() {
    super.initState();
    _router = _buildRouter(context.read<AuthState>());

    // Native deep links: a B2B launch opens exquislearner://l/<ticket> (or an
    // https app link). Forward its path into the router.
    _linkSub = _appLinks.uriLinkStream.listen((uri) {
      final path = uri.path.isEmpty ? '/' : uri.path;
      if (path.startsWith('/l/')) _router.go(path);
    });
  }

  @override
  void dispose() {
    _linkSub?.cancel();
    super.dispose();
  }

  GoRouter _buildRouter(AuthState auth) => GoRouter(
        // The router listens to auth so a login (or logout) moves the user.
        refreshListenable: auth,
        redirect: (context, state) {
          if (!auth.ready) return null; // splash until the saved token is checked
          final loc = state.matchedLocation;
          // A launch link is a public entry point — never bounce it to /login.
          if (loc.startsWith('/l/')) return null;
          if (!auth.signedIn) return loc == '/login' ? null : '/login';
          if (loc == '/login') return '/';
          return null;
        },
        routes: [
          GoRoute(path: '/login', builder: (_, _) => const LoginScreen()),
          GoRoute(path: '/', builder: (_, _) => const HomeShell()),
          GoRoute(path: '/store', builder: (_, _) => const StoreScreen()),
          GoRoute(
            path: '/l/:ticket',
            builder: (_, state) => LaunchScreen(ticket: state.pathParameters['ticket']!),
          ),
          GoRoute(
            path: '/courses/:id',
            builder: (_, state) => CourseScreen(
              courseId: state.pathParameters['id']!,
              title: state.uri.queryParameters['title'] ?? 'Course',
            ),
          ),
          GoRoute(
            path: '/courses/:id/quizzes',
            builder: (_, state) => AssessmentsScreen(
              courseId: state.pathParameters['id']!,
              title: state.uri.queryParameters['title'] ?? 'Course',
            ),
          ),
          GoRoute(
            path: '/courses/:id/tutor',
            builder: (_, state) => TutorScreen(
              courseId: state.pathParameters['id']!,
              title: state.uri.queryParameters['title'] ?? 'Course',
            ),
          ),
          GoRoute(
            path: '/attempts/:id',
            builder: (_, state) => AttemptScreen(attemptId: state.pathParameters['id']!),
          ),
        ],
      );

  @override
  Widget build(BuildContext context) {
    return MaterialApp.router(
      title: 'Learner',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.light(),
      darkTheme: AppTheme.dark(),
      routerConfig: _router,
    );
  }
}
