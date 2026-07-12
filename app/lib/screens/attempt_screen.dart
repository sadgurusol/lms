import 'dart:async';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../api_client.dart';
import '../models.dart';

class AttemptScreen extends StatefulWidget {
  const AttemptScreen({super.key, required this.attemptId});

  final String attemptId;

  @override
  State<AttemptScreen> createState() => _AttemptScreenState();
}

class _AttemptScreenState extends State<AttemptScreen> {
  Attempt? _attempt;
  String? _error;
  bool _submitting = false;

  // Working answers, keyed by assessment_question_id. Seeded from any answers
  // already saved on the attempt so a resume shows prior work.
  final Map<String, Map<String, dynamic>> _responses = {};

  Timer? _clock;
  Duration _remaining = Duration.zero;
  bool _autoSubmitted = false;

  // No-backtrack tests are shown one question at a time. _current is the visible
  // question; _saved tracks answers already persisted (and thus locked on the
  // server) so we never try to re-send them.
  int _current = 0;
  final Set<String> _saved = {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _clock?.cancel();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final attempt = await context.read<ApiClient>().attempt(widget.attemptId);
      _responses.clear();
      for (final q in attempt.questions) {
        if (q.response != null && q.response!.isNotEmpty) {
          _responses[q.assessmentQuestionId] = Map.of(q.response!);
        }
      }
      if (mounted) setState(() => _attempt = attempt);
      _startClock(attempt);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  /// Tick a countdown for a timed, in-progress attempt; auto-submit at zero.
  void _startClock(Attempt attempt) {
    _clock?.cancel();
    if (attempt.expiresAt == null || !attempt.isInProgress) return;

    void tick() {
      final left = attempt.expiresAt!.difference(DateTime.now());
      if (!mounted) return;
      if (left <= Duration.zero) {
        _clock?.cancel();
        if (!_autoSubmitted && !_submitting) {
          _autoSubmitted = true;
          _submit(); // time's up — submit whatever is answered
        }
        setState(() => _remaining = Duration.zero);
        return;
      }
      setState(() => _remaining = left);
    }

    tick();
    _clock = Timer.periodic(const Duration(seconds: 1), (_) => tick());
  }

  Future<void> _submit() async {
    setState(() => _submitting = true);
    final api = context.read<ApiClient>();
    try {
      // Save answers in question order (matters when backtracking is off), then
      // submit. Unanswered questions are scored zero by the server.
      for (final q in _attempt!.questions) {
        final response = _responses[q.assessmentQuestionId];
        if (response != null && response.isNotEmpty && !_saved.contains(q.assessmentQuestionId)) {
          await api.answer(_attempt!.id, q.assessmentQuestionId, response);
        }
      }
      final graded = await api.submitAttempt(_attempt!.id);
      _clock?.cancel();
      if (mounted) setState(() => _attempt = graded);
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final attempt = _attempt;
    final timed = attempt != null && attempt.isInProgress && attempt.expiresAt != null;
    return Scaffold(
      appBar: AppBar(
        title: const Text('Quiz'),
        actions: [
          if (timed)
            Padding(
              padding: const EdgeInsets.only(right: 12),
              child: Center(child: _CountdownChip(remaining: _remaining)),
            ),
        ],
      ),
      body: _error != null
          ? Center(child: Padding(padding: const EdgeInsets.all(24), child: Text(_error!)))
          : attempt == null
              ? const Center(child: CircularProgressIndicator())
              : attempt.isInProgress
                  ? _buildForm(attempt)
                  : _buildResult(attempt),
    );
  }

  Widget _buildForm(Attempt attempt) {
    return attempt.allowBacktrack ? _buildScrollForm(attempt) : _buildPagedForm(attempt);
  }

  /// The default: every question on one scrollable page, freely editable.
  Widget _buildScrollForm(Attempt attempt) {
    return Column(
      children: [
        Expanded(
          child: ListView.separated(
            padding: const EdgeInsets.all(16),
            itemCount: attempt.questions.length,
            separatorBuilder: (_, _) => const Divider(height: 32),
            itemBuilder: (context, i) => _QuestionField(
              index: i,
              question: attempt.questions[i],
              response: _responses[attempt.questions[i].assessmentQuestionId],
              onChanged: (r) =>
                  setState(() => _responses[attempt.questions[i].assessmentQuestionId] = r),
            ),
          ),
        ),
        SafeArea(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: SizedBox(
              width: double.infinity,
              child: FilledButton(
                onPressed: _submitting ? null : _confirmSubmit,
                child: _submitting
                    ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2))
                    : const Text('Submit'),
              ),
            ),
          ),
        ),
      ],
    );
  }

  /// No-backtrack tests: one question at a time, each locked in as you advance.
  Widget _buildPagedForm(Attempt attempt) {
    final total = attempt.questions.length;
    final q = attempt.questions[_current];
    final isLast = _current == total - 1;

    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              ClipRRect(
                borderRadius: BorderRadius.circular(4),
                child: LinearProgressIndicator(value: (_current + 1) / total, minHeight: 6),
              ),
              const SizedBox(height: 6),
              Text('Question ${_current + 1} of $total · you can\'t go back',
                  style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12)),
            ],
          ),
        ),
        Expanded(
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              _QuestionField(
                index: _current,
                question: q,
                response: _responses[q.assessmentQuestionId],
                onChanged: (r) => setState(() => _responses[q.assessmentQuestionId] = r),
              ),
            ],
          ),
        ),
        SafeArea(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: SizedBox(
              width: double.infinity,
              child: FilledButton(
                onPressed: _submitting ? null : (isLast ? _confirmSubmit : _advance),
                child: _submitting
                    ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2))
                    : Text(isLast ? 'Finish' : 'Next'),
              ),
            ),
          ),
        ),
      ],
    );
  }

  /// Persist the current answer (locking it server-side) before moving on. If
  /// the save fails we stay put rather than stranding the answer.
  Future<void> _advance() async {
    final q = _attempt!.questions[_current];
    final r = _responses[q.assessmentQuestionId];
    if (r != null && r.isNotEmpty && !_saved.contains(q.assessmentQuestionId)) {
      setState(() => _submitting = true);
      try {
        await context.read<ApiClient>().answer(_attempt!.id, q.assessmentQuestionId, r);
        _saved.add(q.assessmentQuestionId);
      } on ApiException catch (e) {
        if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
        return;
      } finally {
        if (mounted) setState(() => _submitting = false);
      }
    }
    if (mounted) setState(() => _current++);
  }

  Future<void> _confirmSubmit() async {
    final answered = _responses.values.where((r) => r.isNotEmpty).length;
    final total = _attempt!.questions.length;
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Submit quiz?'),
        content: Text('You answered $answered of $total questions. You can\'t change your answers after submitting.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Keep working')),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Submit')),
        ],
      ),
    );
    if (ok == true) await _submit();
  }

  Widget _buildResult(Attempt attempt) {
    final pct = (attempt.maxScore ?? 0) > 0 ? (attempt.score! / attempt.maxScore! * 100) : 0.0;
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Card(
          child: Padding(
            padding: const EdgeInsets.all(20),
            child: Column(
              children: [
                Text('${attempt.score?.toStringAsFixed(0) ?? '—'} / ${attempt.maxScore?.toStringAsFixed(0) ?? '—'}',
                    style: Theme.of(context).textTheme.headlineMedium),
                Text('${pct.toStringAsFixed(0)}%'),
                if (attempt.passed != null) ...[
                  const SizedBox(height: 8),
                  Chip(
                    label: Text(attempt.passed! ? 'Passed' : 'Not passed'),
                    backgroundColor: attempt.passed!
                        ? Colors.green.withValues(alpha: 0.15)
                        : Colors.red.withValues(alpha: 0.15),
                  ),
                ],
                if (attempt.state == 'awaiting_review')
                  const Padding(
                    padding: EdgeInsets.only(top: 8),
                    child: Text('Some answers need a teacher to grade.'),
                  ),
              ],
            ),
          ),
        ),
        const SizedBox(height: 16),
        if (!attempt.answersRevealed)
          Text('Answers are not shown for this quiz.', style: TextStyle(color: Theme.of(context).hintColor))
        else
          for (var i = 0; i < attempt.questions.length; i++)
            _ResultTile(index: i, question: attempt.questions[i]),
      ],
    );
  }
}

/// One question with the right input for its type.
class _QuestionField extends StatelessWidget {
  const _QuestionField({
    required this.index,
    required this.question,
    required this.response,
    required this.onChanged,
  });

  final int index;
  final AttemptQuestion question;
  final Map<String, dynamic>? response;
  final ValueChanged<Map<String, dynamic>> onChanged;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Question ${index + 1} · ${question.points.toStringAsFixed(0)} pts',
            style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12)),
        const SizedBox(height: 4),
        Text(question.stem, style: Theme.of(context).textTheme.titleMedium),
        const SizedBox(height: 8),
        _input(context),
      ],
    );
  }

  Widget _input(BuildContext context) {
    switch (question.type) {
      case 'mcq_single':
        return RadioGroup<String>(
          groupValue: response?['option_id'] as String?,
          onChanged: (v) => onChanged({'option_id': v}),
          child: Column(
            children: [
              for (final o in question.options)
                RadioListTile<String>(
                  value: o.id,
                  title: Text(o.text),
                  contentPadding: EdgeInsets.zero,
                ),
            ],
          ),
        );
      case 'mcq_multi':
        final chosen = ((response?['option_ids'] as List?) ?? const []).cast<String>();
        return Column(
          children: [
            for (final o in question.options)
              CheckboxListTile(
                value: chosen.contains(o.id),
                title: Text(o.text),
                contentPadding: EdgeInsets.zero,
                controlAffinity: ListTileControlAffinity.leading,
                onChanged: (v) {
                  final next = [...chosen];
                  v == true ? next.add(o.id) : next.remove(o.id);
                  onChanged({'option_ids': next});
                },
              ),
          ],
        );
      case 'true_false':
        return RadioGroup<bool>(
          groupValue: response?['answer'] as bool?,
          onChanged: (v) => onChanged({'answer': v}),
          child: const Column(
            children: [
              RadioListTile<bool>(value: true, title: Text('True'), contentPadding: EdgeInsets.zero),
              RadioListTile<bool>(value: false, title: Text('False'), contentPadding: EdgeInsets.zero),
            ],
          ),
        );
      case 'numeric':
        return TextFormField(
          initialValue: response?['answer']?.toString(),
          keyboardType: const TextInputType.numberWithOptions(decimal: true, signed: true),
          decoration: const InputDecoration(border: OutlineInputBorder(), hintText: 'Your answer'),
          onChanged: (v) => onChanged({'answer': v}),
        );
      default: // short_answer, essay
        return TextFormField(
          initialValue: response?['text'] as String?,
          maxLines: question.type == 'essay' ? 5 : 1,
          decoration: const InputDecoration(border: OutlineInputBorder(), hintText: 'Your answer'),
          onChanged: (v) => onChanged({'text': v}),
        );
    }
  }
}

class _ResultTile extends StatelessWidget {
  const _ResultTile({required this.index, required this.question});

  final int index;
  final AttemptQuestion question;

  @override
  Widget build(BuildContext context) {
    final correct = question.isCorrect;
    final icon = correct == null
        ? Icons.help_outline
        : (correct ? Icons.check_circle : Icons.cancel);
    final color = correct == null
        ? Theme.of(context).hintColor
        : (correct ? Colors.green : Colors.red);

    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(icon, color: color, size: 20),
              const SizedBox(width: 8),
              Expanded(child: Text('${index + 1}. ${question.stem}',
                  style: const TextStyle(fontWeight: FontWeight.w500))),
              if (question.pointsAwarded != null)
                Text('${question.pointsAwarded!.toStringAsFixed(0)}/${question.points.toStringAsFixed(0)}'),
            ],
          ),
          for (final o in question.options)
            Padding(
              padding: const EdgeInsets.only(left: 28, top: 2),
              child: Text(
                '${o.isCorrect == true ? '✓ ' : '• '}${o.text}',
                style: TextStyle(
                  color: o.isCorrect == true ? Colors.green.shade700 : Theme.of(context).colorScheme.onSurfaceVariant,
                ),
              ),
            ),
        ],
      ),
    );
  }
}

/// A live mm:ss countdown that turns red in the final minute.
class _CountdownChip extends StatelessWidget {
  const _CountdownChip({required this.remaining});

  final Duration remaining;

  @override
  Widget build(BuildContext context) {
    final urgent = remaining.inSeconds <= 60;
    final m = remaining.inMinutes.toString().padLeft(2, '0');
    final s = (remaining.inSeconds % 60).toString().padLeft(2, '0');
    final color = urgent ? Colors.red : Theme.of(context).colorScheme.primary;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(20),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(Icons.timer_outlined, size: 16, color: color),
          const SizedBox(width: 4),
          Text('$m:$s', style: TextStyle(color: color, fontWeight: FontWeight.w700, fontFeatures: const [FontFeature.tabularFigures()])),
        ],
      ),
    );
  }
}
