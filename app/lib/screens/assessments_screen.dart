import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../api_client.dart';
import '../models.dart';
import '../responsive.dart';

class AssessmentsScreen extends StatefulWidget {
  const AssessmentsScreen({super.key, required this.courseId, required this.title});

  final String courseId;
  final String title;

  @override
  State<AssessmentsScreen> createState() => _AssessmentsScreenState();
}

class _AssessmentsScreenState extends State<AssessmentsScreen> {
  late Future<List<AssessmentSummary>> _future;
  String? _starting; // assessment id currently being started

  @override
  void initState() {
    super.initState();
    _future = context.read<ApiClient>().courseAssessments(widget.courseId);
  }

  void _reload() =>
      setState(() => _future = context.read<ApiClient>().courseAssessments(widget.courseId));

  Future<void> _open(AssessmentSummary a) async {
    // Resume an in-progress attempt, or start a fresh one.
    if (a.inProgressAttemptId != null) {
      context.push('/attempts/${a.inProgressAttemptId}');
      return;
    }
    setState(() => _starting = a.id);
    try {
      final attempt = await context.read<ApiClient>().startAttempt(a.id);
      if (mounted) await context.push('/attempts/${attempt.id}');
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    } finally {
      if (mounted) setState(() => _starting = null);
    }
    if (mounted) _reload(); // refresh state after returning from an attempt
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text('${widget.title} · quizzes')),
      body: RefreshIndicator(
        onRefresh: () async => _reload(),
        child: FutureBuilder<List<AssessmentSummary>>(
          future: _future,
          builder: (context, snapshot) {
            if (snapshot.connectionState == ConnectionState.waiting) {
              return const Center(child: CircularProgressIndicator());
            }
            if (snapshot.hasError) {
              return Center(
                child: Text(snapshot.error is ApiException
                    ? (snapshot.error as ApiException).message
                    : 'Something went wrong.'),
              );
            }
            final items = snapshot.data ?? const [];
            if (items.isEmpty) {
              return ListView(children: const [
                SizedBox(height: 120),
                Center(child: Text('No quizzes on this course.')),
              ]);
            }
            return MaxWidth(
              maxWidth: 720,
              child: ListView.separated(
                padding: const EdgeInsets.all(12),
                itemCount: items.length,
                separatorBuilder: (_, _) => const SizedBox(height: 8),
                itemBuilder: (context, i) => _AssessmentCard(
                  a: items[i],
                  busy: _starting == items[i].id,
                  onOpen: () => _open(items[i]),
                ),
              ),
            );
          },
        ),
      ),
    );
  }
}

class _AssessmentCard extends StatelessWidget {
  const _AssessmentCard({required this.a, required this.busy, required this.onOpen});

  final AssessmentSummary a;
  final bool busy;
  final VoidCallback onOpen;

  @override
  Widget build(BuildContext context) {
    final resuming = a.inProgressAttemptId != null;
    final buttonLabel = resuming ? 'Resume' : (a.attemptsUsed > 0 ? 'Try again' : 'Start');
    final detail = <String>[
      '${a.questionCount} questions',
      if (a.kind == 'test') 'Test' else 'Quiz',
      if (a.maxAttempts != null) 'up to ${a.maxAttempts} attempts',
      if (a.timeLimitS != null) '${(a.timeLimitS! / 60).round()} min',
    ].join(' · ');

    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(child: Text(a.title, style: Theme.of(context).textTheme.titleMedium)),
                if (a.passed)
                  const Chip(label: Text('Passed'), visualDensity: VisualDensity.compact),
              ],
            ),
            const SizedBox(height: 4),
            Text(detail, style: TextStyle(color: Theme.of(context).hintColor)),
            if (a.bestPercentage != null)
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text('Best: ${a.bestPercentage!.toStringAsFixed(0)}%'),
              ),
            const SizedBox(height: 12),
            Align(
              alignment: Alignment.centerRight,
              child: FilledButton(
                onPressed: (a.canStart || resuming) && !busy ? onOpen : null,
                child: busy
                    ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2))
                    : Text(buttonLabel),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
