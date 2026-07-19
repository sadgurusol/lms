import 'dart:async';

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../api_client.dart';
import '../models.dart';
import '../progress_tracker.dart';
import '../responsive.dart';
import '../widgets/block_view.dart';

/// Reads a course as a collapsible outline: units, chapters and topics start
/// folded, and the learner expands the section they want to read. The structure
/// mirrors the course's own schema hierarchy; a node is "content" (shows blocks
/// when opened) if it carries any, otherwise it's a grouping header.
///
/// Progress follows expansion, not scrolling: opening a content section makes it
/// the active one (time accrues there), moving to another marks the previous
/// completed, and closing a section completes it.
class CourseScreen extends StatefulWidget {
  const CourseScreen({super.key, required this.courseId, required this.title});

  final String courseId;
  final String title;

  @override
  State<CourseScreen> createState() => _CourseScreenState();
}

class _CourseScreenState extends State<CourseScreen> {
  final _scroll = ScrollController();

  CourseContent? _content;
  String? _error;
  ProgressTracker? _tracker;
  Timer? _timer;
  int _ticks = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final api = context.read<ApiClient>();
    try {
      final content = await api.courseContent(widget.courseId);
      if (content.publicationId != null) {
        _tracker = ProgressTracker(api, content.publicationId!);
        _timer = Timer.periodic(const Duration(seconds: 1), _onTick);
      }
      if (mounted) setState(() => _content = content);
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

  /// Drive progress from a content section opening or closing.
  void _onExpansion(ContentNode node, bool expanded) {
    final tracker = _tracker;
    if (tracker == null || node.blocks.isEmpty) return; // grouping nodes aren't tracked

    if (expanded) {
      final previous = tracker.active;
      if (previous != null && previous != node.id) tracker.mark(previous, 'completed');
      tracker.active = node.id;
      tracker.mark(node.id, 'in_progress');
    } else if (tracker.active == node.id) {
      tracker.mark(node.id, 'completed');
      tracker.active = null;
    }
  }

  @override
  void dispose() {
    _timer?.cancel();
    final tracker = _tracker;
    if (tracker?.active != null) tracker!.mark(tracker.active!, 'completed');
    tracker?.flush(); // best-effort final flush
    _scroll.dispose();
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
            onPressed: () => context.push('/courses/${widget.courseId}/tutor?title=${Uri.encodeComponent(widget.title)}'),
          ),
          IconButton(
            icon: const Icon(Icons.quiz_rounded),
            tooltip: 'Quizzes',
            onPressed: () =>
                context.push('/courses/${widget.courseId}/quizzes?title=${Uri.encodeComponent(widget.title)}'),
          ),
        ],
      ),
      body: _error != null
          ? Center(child: Padding(padding: const EdgeInsets.all(24), child: Text(_error!)))
          : _content == null
          ? const Center(child: CircularProgressIndicator())
          : _content!.tree.isEmpty
          ? const Center(child: Text('This course has no content yet.'))
          : MaxWidth(
              maxWidth: 760,
              child: ListView(
                controller: _scroll,
                padding: const EdgeInsets.fromLTRB(12, 8, 12, 64),
                children: [for (final node in _content!.tree) _nodeTile(node, 0)],
              ),
            ),
    );
  }

  Widget _nodeTile(ContentNode node, int depth) {
    final hasContent = node.blocks.isNotEmpty;
    final hasChildren = node.children.isNotEmpty;
    final headingStyle = _headingStyle(context, depth);

    // A leaf with no content and no children is just a label.
    if (!hasContent && !hasChildren) {
      final tile = ListTile(
        contentPadding: EdgeInsets.only(left: 16.0 + depth * 12, right: 16),
        title: Text(node.label, style: headingStyle),
        subtitle: _summary(context, node),
      );
      return depth == 0 ? Card(margin: const EdgeInsets.only(bottom: 10), child: tile) : tile;
    }

    final tile = ExpansionTile(
      key: PageStorageKey(node.id),
      onExpansionChanged: (expanded) => _onExpansion(node, expanded),
      tilePadding: EdgeInsets.only(left: 16.0 + depth * 12, right: 16),
      childrenPadding: EdgeInsets.zero,
      shape: const Border(),
      collapsedShape: const Border(),
      title: Text(node.label, style: headingStyle),
      subtitle: _summary(context, node),
      children: [
        if (hasContent)
          Padding(
            padding: EdgeInsets.only(left: 24.0 + depth * 12, right: 16, bottom: 12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                for (final block in node.blocks)
                  Padding(padding: const EdgeInsets.only(bottom: 12), child: BlockView(block: block)),
              ],
            ),
          ),
        for (final child in node.children) _nodeTile(child, depth + 1),
      ],
    );

    return depth == 0
        ? Card(margin: const EdgeInsets.only(bottom: 10), clipBehavior: Clip.antiAlias, child: tile)
        : tile;
  }

  TextStyle? _headingStyle(BuildContext context, int depth) {
    final text = Theme.of(context).textTheme;
    return switch (depth) {
      0 => text.titleLarge,
      1 => text.titleMedium,
      _ => text.titleSmall,
    };
  }

  Widget? _summary(BuildContext context, ContentNode node) {
    if (node.summary == null || node.summary!.isEmpty) return null;
    return Text(node.summary!, style: TextStyle(color: Theme.of(context).hintColor));
  }
}
