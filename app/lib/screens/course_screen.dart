import 'dart:async';

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../api_client.dart';
import '../models.dart';
import '../progress_tracker.dart';
import '../widgets/block_view.dart';

/// Reads a course and reports progress as the learner scrolls: time accrues to
/// the section on screen, and a section counts as completed once scrolled past.
class CourseScreen extends StatefulWidget {
  const CourseScreen({super.key, required this.courseId, required this.title});

  final String courseId;
  final String title;

  @override
  State<CourseScreen> createState() => _CourseScreenState();
}

class _CourseScreenState extends State<CourseScreen> {
  final _scroll = ScrollController();
  final _listKey = GlobalKey();
  final Map<String, GlobalKey> _nodeKeys = {};

  CourseContent? _content;
  String? _error;
  ProgressTracker? _tracker;
  Timer? _timer;
  int _ticks = 0;
  DateTime _lastEval = DateTime.fromMillisecondsSinceEpoch(0);

  @override
  void initState() {
    super.initState();
    _scroll.addListener(_onScroll);
    _load();
  }

  Future<void> _load() async {
    final api = context.read<ApiClient>();
    try {
      final content = await api.courseContent(widget.courseId);
      for (final id in content.contentNodeIds) {
        _nodeKeys[id] = GlobalKey();
      }
      if (content.publicationId != null) {
        _tracker = ProgressTracker(api, content.publicationId!);
        _timer = Timer.periodic(const Duration(seconds: 1), _onTick);
      }
      if (mounted) setState(() => _content = content);
      WidgetsBinding.instance.addPostFrameCallback((_) => _evaluate());
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } catch (_) {
      if (mounted) setState(() => _error = 'Could not open this course.');
    }
  }

  void _onTick(Timer _) {
    _tracker?.tick();
    if (++_ticks % 15 == 0) _tracker?.flush();
  }

  void _onScroll() {
    final now = DateTime.now();
    if (now.difference(_lastEval).inMilliseconds < 250) return;
    _lastEval = now;
    _evaluate();
  }

  /// Decide which sections are visible / read, using each section's render box
  /// against the scroll viewport.
  void _evaluate() {
    final tracker = _tracker;
    final listBox = _listKey.currentContext?.findRenderObject() as RenderBox?;
    if (tracker == null || listBox == null) return;

    final listTop = listBox.localToGlobal(Offset.zero).dy;
    final listBottom = listTop + listBox.size.height;
    final atEnd = _scroll.hasClients &&
        _scroll.position.pixels >= _scroll.position.maxScrollExtent - 8;

    String? topmost;
    double topmostY = double.infinity;

    for (final entry in _nodeKeys.entries) {
      final box = entry.value.currentContext?.findRenderObject() as RenderBox?;
      if (box == null) continue;
      final top = box.localToGlobal(Offset.zero).dy;
      final bottom = top + box.size.height;
      final visible = bottom > listTop && top < listBottom;

      if (bottom <= listTop + 4 || (atEnd && visible)) {
        tracker.mark(entry.key, 'completed');
      } else if (visible) {
        tracker.mark(entry.key, 'in_progress');
        if (top < topmostY) {
          topmostY = top;
          topmost = entry.key;
        }
      }
    }
    tracker.active = topmost;
  }

  @override
  void dispose() {
    _timer?.cancel();
    _scroll.dispose();
    _tracker?.flush(); // best-effort final flush; the tracker holds its own client
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(widget.title),
        actions: [
          IconButton(
            icon: const Icon(Icons.auto_awesome),
            tooltip: 'Ask the tutor',
            onPressed: () => context.push(
              '/courses/${widget.courseId}/tutor?title=${Uri.encodeComponent(widget.title)}',
            ),
          ),
          IconButton(
            icon: const Icon(Icons.quiz_rounded),
            tooltip: 'Quizzes',
            onPressed: () => context.push(
              '/courses/${widget.courseId}/quizzes?title=${Uri.encodeComponent(widget.title)}',
            ),
          ),
        ],
      ),
      body: _error != null
          ? Center(child: Padding(padding: const EdgeInsets.all(24), child: Text(_error!)))
          : _content == null
              ? const Center(child: CircularProgressIndicator())
              : _content!.tree.isEmpty
                  ? const Center(child: Text('This course has no content yet.'))
                  : ListView(
                      key: _listKey,
                      controller: _scroll,
                      padding: const EdgeInsets.fromLTRB(16, 8, 16, 64),
                      children: [
                        for (final node in _content!.tree)
                          _NodeSection(node: node, depth: 0, keys: _nodeKeys),
                      ],
                    ),
    );
  }
}

class _NodeSection extends StatelessWidget {
  const _NodeSection({required this.node, required this.depth, required this.keys});

  final ContentNode node;
  final int depth;
  final Map<String, GlobalKey> keys;

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    final headingStyle = switch (depth) {
      0 => text.headlineSmall,
      1 => text.titleLarge,
      2 => text.titleMedium,
      _ => text.titleSmall,
    };

    final section = Container(
      key: keys[node.id],
      margin: EdgeInsets.only(top: depth == 0 ? 20 : 14),
      padding: EdgeInsets.only(left: depth > 0 ? 12 : 0),
      decoration: depth > 0
          ? BoxDecoration(border: Border(left: BorderSide(color: Theme.of(context).dividerColor)))
          : null,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(node.label, style: headingStyle),
          if (node.summary != null && node.summary!.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text(node.summary!, style: TextStyle(color: Theme.of(context).hintColor)),
            ),
          if (node.blocks.isNotEmpty)
            Card(
              margin: const EdgeInsets.only(top: 12),
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    for (final block in node.blocks)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 12),
                        child: BlockView(block: block),
                      ),
                  ],
                ),
              ),
            ),
          for (final child in node.children) _NodeSection(node: child, depth: depth + 1, keys: keys),
        ],
      ),
    );

    return section;
  }
}
