import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../api_client.dart';
import '../models.dart';
import '../responsive.dart';

/// The "Courses" tab — a card list of everything the learner is enrolled in.
/// Body-only: the surrounding scaffold and nav live in HomeShell.
class CoursesTab extends StatefulWidget {
  const CoursesTab({super.key});

  @override
  State<CoursesTab> createState() => _CoursesTabState();
}

class _CoursesTabState extends State<CoursesTab> {
  late Future<List<CourseSummary>> _future;

  @override
  void initState() {
    super.initState();
    _future = context.read<ApiClient>().courses();
  }

  void _reload() => setState(() => _future = context.read<ApiClient>().courses());

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: () async => _reload(),
      child: FutureBuilder<List<CourseSummary>>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError) {
            return _CenteredScroll(
              child: Text(
                snapshot.error is ApiException ? (snapshot.error as ApiException).message : 'Something went wrong.',
              ),
            );
          }
          final courses = snapshot.data ?? const [];
          if (courses.isEmpty) {
            return _CenteredScroll(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(Icons.school_outlined, size: 48, color: Theme.of(context).hintColor),
                  const SizedBox(height: 12),
                  const Text('You are not enrolled in any courses yet.', textAlign: TextAlign.center),
                  const SizedBox(height: 16),
                  FilledButton.icon(
                    onPressed: () => context.push('/store'),
                    icon: const Icon(Icons.storefront_outlined),
                    label: const Text('Explore courses'),
                  ),
                ],
              ),
            );
          }
          if (context.isTablet) {
            return MaxWidth(
              maxWidth: 960,
              child: GridView.builder(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
                gridDelegate: const SliverGridDelegateWithMaxCrossAxisExtent(
                  maxCrossAxisExtent: 440,
                  mainAxisExtent: 92,
                  crossAxisSpacing: 12,
                  mainAxisSpacing: 12,
                ),
                itemCount: courses.length,
                itemBuilder: (context, i) => _CourseCard(course: courses[i]),
              ),
            );
          }
          return ListView.separated(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
            itemCount: courses.length,
            separatorBuilder: (_, _) => const SizedBox(height: 12),
            itemBuilder: (context, i) => _CourseCard(course: courses[i]),
          );
        },
      ),
    );
  }
}

class _CourseCard extends StatelessWidget {
  const _CourseCard({required this.course});

  final CourseSummary course;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final subtitle = [course.code, course.subject, course.gradeBand].whereType<String>().join(' · ');

    return Card(
      child: InkWell(
        borderRadius: BorderRadius.circular(18),
        onTap: () => context.push('/courses/${course.id}?title=${Uri.encodeComponent(course.title)}'),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Row(
            children: [
              Container(
                width: 48,
                height: 48,
                decoration: BoxDecoration(color: scheme.primaryContainer, borderRadius: BorderRadius.circular(12)),
                child: Icon(Icons.menu_book_rounded, color: scheme.onPrimaryContainer),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      course.title,
                      style: Theme.of(context).textTheme.titleMedium,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                    ),
                    if (subtitle.isNotEmpty)
                      Padding(
                        padding: const EdgeInsets.only(top: 2),
                        child: Text(subtitle, style: TextStyle(color: Theme.of(context).hintColor, fontSize: 13)),
                      ),
                  ],
                ),
              ),
              Icon(Icons.chevron_right_rounded, color: Theme.of(context).hintColor),
            ],
          ),
        ),
      ),
    );
  }
}

class _CenteredScroll extends StatelessWidget {
  const _CenteredScroll({required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    return ListView(
      children: [
        const SizedBox(height: 140),
        Center(
          child: Padding(padding: const EdgeInsets.all(24), child: child),
        ),
      ],
    );
  }
}
