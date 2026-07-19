import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../api_client.dart';
import '../auth.dart';
import '../models.dart';
import '../responsive.dart';
import '../theme.dart';

class DashboardTab extends StatefulWidget {
  const DashboardTab({super.key});

  @override
  State<DashboardTab> createState() => _DashboardTabState();
}

class _DashboardTabState extends State<DashboardTab> {
  late Future<DashboardData> _future;

  @override
  void initState() {
    super.initState();
    _future = context.read<ApiClient>().dashboard();
  }

  void _reload() => setState(() => _future = context.read<ApiClient>().dashboard());

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: () async => _reload(),
      child: FutureBuilder<DashboardData>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError) {
            return ListView(
              children: [
                const SizedBox(height: 140),
                Center(
                  child: Text(
                    snapshot.error is ApiException
                        ? (snapshot.error as ApiException).message
                        : 'Could not load your dashboard.',
                  ),
                ),
              ],
            );
          }
          final data = snapshot.data!;
          final isTablet = context.isTablet;

          final continueLearning = data.courses.isEmpty
              ? null
              : Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    _SectionHeader('Continue learning'),
                    const SizedBox(height: 8),
                    for (final c in data.courses) _CourseProgressCard(course: c),
                  ],
                );
          final recentQuizzes = data.recentQuizzes.isEmpty
              ? null
              : Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    _SectionHeader('Recent quizzes'),
                    const SizedBox(height: 8),
                    _RecentQuizzes(quizzes: data.recentQuizzes),
                  ],
                );

          // On a tablet the two sections sit side by side; on a phone they stack.
          Widget sections;
          if (isTablet && continueLearning != null && recentQuizzes != null) {
            sections = Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(child: continueLearning),
                const SizedBox(width: 20),
                Expanded(child: recentQuizzes),
              ],
            );
          } else {
            sections = Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                ?continueLearning,
                if (continueLearning != null && recentQuizzes != null) const SizedBox(height: 24),
                ?recentQuizzes,
              ],
            );
          }

          return MaxWidth(
            maxWidth: 1040,
            child: ListView(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
              children: [
                _Hero(name: context.read<AuthState>().userName, stats: data.stats),
                const SizedBox(height: 20),
                _StatGrid(stats: data.stats, crossAxisCount: isTablet ? 4 : 2),
                const SizedBox(height: 24),
                if (continueLearning != null || recentQuizzes != null)
                  sections
                else
                  Padding(
                    padding: const EdgeInsets.only(top: 60),
                    child: Center(
                      child: Text(
                        'Nothing here yet — enrol in a course to get started.',
                        style: TextStyle(color: Theme.of(context).hintColor),
                      ),
                    ),
                  ),
              ],
            ),
          );
        },
      ),
    );
  }
}

class _Hero extends StatelessWidget {
  const _Hero({required this.name, required this.stats});

  final String? name;
  final DashboardStats stats;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final overall = stats.coursesEnrolled == 0 ? 0.0 : stats.coursesCompleted / stats.coursesEnrolled;

    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(gradient: AppTheme.heroGradient(scheme), borderRadius: BorderRadius.circular(22)),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Welcome back${name != null ? ',' : ''}',
                  style: TextStyle(color: Colors.white.withValues(alpha: 0.85), fontSize: 14),
                ),
                if (name != null)
                  Text(
                    name!,
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 24,
                      fontWeight: FontWeight.w800,
                      letterSpacing: -0.5,
                    ),
                  ),
                const SizedBox(height: 10),
                Text(
                  stats.coursesEnrolled == 0
                      ? 'Ready when you are.'
                      : '${stats.coursesCompleted} of ${stats.coursesEnrolled} courses complete',
                  style: TextStyle(color: Colors.white.withValues(alpha: 0.9)),
                ),
              ],
            ),
          ),
          _Ring(value: overall, color: Colors.white),
        ],
      ),
    );
  }
}

class _Ring extends StatelessWidget {
  const _Ring({required this.value, required this.color});

  final double value;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: 68,
      height: 68,
      child: Stack(
        alignment: Alignment.center,
        children: [
          SizedBox(
            width: 68,
            height: 68,
            child: CircularProgressIndicator(
              value: value.clamp(0.0, 1.0),
              strokeWidth: 6,
              backgroundColor: color.withValues(alpha: 0.25),
              valueColor: AlwaysStoppedAnimation(color),
            ),
          ),
          Text(
            '${(value * 100).round()}%',
            style: TextStyle(color: color, fontWeight: FontWeight.w700, fontSize: 15),
          ),
        ],
      ),
    );
  }
}

class _StatGrid extends StatelessWidget {
  const _StatGrid({required this.stats, this.crossAxisCount = 2});

  final DashboardStats stats;
  final int crossAxisCount;

  @override
  Widget build(BuildContext context) {
    final avg = stats.averageQuizPercentage;
    final tiles = [
      _StatTile(icon: Icons.school_rounded, label: 'Courses', value: '${stats.coursesEnrolled}'),
      _StatTile(icon: Icons.schedule_rounded, label: 'Minutes', value: '${stats.minutesSpent}'),
      _StatTile(icon: Icons.quiz_rounded, label: 'Quizzes', value: '${stats.quizzesTaken}'),
      _StatTile(icon: Icons.trending_up_rounded, label: 'Avg score', value: avg == null ? '—' : '${avg.round()}%'),
    ];
    return GridView.count(
      crossAxisCount: crossAxisCount,
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      mainAxisSpacing: 12,
      crossAxisSpacing: 12,
      childAspectRatio: crossAxisCount >= 4 ? 2.0 : 2.4,
      children: tiles,
    );
  }
}

class _StatTile extends StatelessWidget {
  const _StatTile({required this.icon, required this.label, required this.value});

  final IconData icon;
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Card(
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        child: Row(
          children: [
            Icon(icon, color: scheme.primary, size: 26),
            const SizedBox(width: 12),
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Text(value, style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
                Text(label, style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12)),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _CourseProgressCard extends StatelessWidget {
  const _CourseProgressCard({required this.course});

  final CourseProgress course;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Card(
        child: InkWell(
          borderRadius: BorderRadius.circular(18),
          onTap: () => context.push('/courses/${course.id}?title=${Uri.encodeComponent(course.title)}'),
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        course.title,
                        style: Theme.of(context).textTheme.titleMedium,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                    Text(
                      '${course.percent.round()}%',
                      style: TextStyle(fontWeight: FontWeight.w700, color: scheme.primary),
                    ),
                  ],
                ),
                const SizedBox(height: 10),
                ClipRRect(
                  borderRadius: BorderRadius.circular(8),
                  child: LinearProgressIndicator(
                    value: (course.percent / 100).clamp(0.0, 1.0),
                    minHeight: 8,
                    backgroundColor: scheme.surfaceContainerHighest,
                  ),
                ),
                const SizedBox(height: 6),
                Text(
                  '${course.completedNodes} of ${course.totalNodes} sections',
                  style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _RecentQuizzes extends StatelessWidget {
  const _RecentQuizzes({required this.quizzes});

  final List<RecentQuiz> quizzes;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Column(
        children: [
          for (var i = 0; i < quizzes.length; i++) ...[
            if (i > 0) const Divider(height: 1, indent: 16, endIndent: 16),
            _quizRow(context, quizzes[i]),
          ],
        ],
      ),
    );
  }

  Widget _quizRow(BuildContext context, RecentQuiz q) {
    final good = q.percentage >= 60;
    final color = q.passed == false || !good ? Colors.orange : Colors.green;
    return ListTile(
      leading: CircleAvatar(
        backgroundColor: color.withValues(alpha: 0.15),
        child: Text(
          '${q.percentage.round()}',
          style: TextStyle(color: color, fontWeight: FontWeight.w700, fontSize: 13),
        ),
      ),
      title: Text(q.title, maxLines: 1, overflow: TextOverflow.ellipsis),
      trailing: q.passed == null
          ? null
          : Icon(
              q.passed! ? Icons.check_circle : Icons.cancel,
              color: q.passed! ? Colors.green : Colors.orange,
              size: 20,
            ),
    );
  }
}

class _SectionHeader extends StatelessWidget {
  const _SectionHeader(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    return Text(text, style: Theme.of(context).textTheme.titleLarge);
  }
}
