import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../api_client.dart';
import '../models.dart';

/// A chat with the AI tutor for one course. The tutor answers from the course's
/// own material and cites the sections it draws on.
class TutorScreen extends StatefulWidget {
  const TutorScreen({super.key, required this.courseId, required this.title});

  final String courseId;
  final String title;

  @override
  State<TutorScreen> createState() => _TutorScreenState();
}

class _TutorScreenState extends State<TutorScreen> {
  final _messages = <TutorMessage>[];
  final _input = TextEditingController();
  final _scroll = ScrollController();

  String? _conversationId;
  String? _error;
  bool _starting = true;
  bool _sending = false;

  // The assistant reply currently being streamed: null when idle, '' while
  // waiting for the first token, then the text as it accumulates.
  String? _partial;
  List<TutorCitation> _partialCitations = const [];

  @override
  void initState() {
    super.initState();
    _start();
  }

  @override
  void dispose() {
    _input.dispose();
    _scroll.dispose();
    super.dispose();
  }

  Future<void> _start() async {
    try {
      final id = await context.read<ApiClient>().startTutorConversation(widget.courseId);
      if (mounted) setState(() => _conversationId = id);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _starting = false);
    }
  }

  Future<void> _send() async {
    final text = _input.text.trim();
    if (text.isEmpty || _sending || _conversationId == null) return;

    setState(() {
      _messages.add(TutorMessage(role: 'user', content: text));
      _sending = true;
      _partial = '';
      _partialCitations = const [];
      _input.clear();
    });
    _scrollToEnd();

    try {
      final stream = context.read<ApiClient>().streamTutorMessage(_conversationId!, text);
      await for (final event in stream) {
        if (!mounted) return;
        switch (event) {
          case TutorDelta(:final text):
            setState(() => _partial = (_partial ?? '') + text);
            _scrollToEnd();
          case TutorDone(:final citations):
            setState(() => _partialCitations = citations);
        }
      }
      if (mounted && (_partial ?? '').isNotEmpty) {
        setState(() => _messages.add(
              TutorMessage(role: 'assistant', content: _partial!, citations: _partialCitations),
            ));
      }
    } on ApiException catch (e) {
      if (mounted) {
        setState(() => _messages.add(TutorMessage(
              role: 'assistant',
              content: "Sorry — I couldn't answer that just now. ${e.message}",
            )));
      }
    } finally {
      if (mounted) {
        setState(() {
          _sending = false;
          _partial = null;
          _partialCitations = const [];
        });
      }
      _scrollToEnd();
    }
  }

  void _scrollToEnd() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (_scroll.hasClients) {
        _scroll.animateTo(_scroll.position.maxScrollExtent,
            duration: const Duration(milliseconds: 250), curve: Curves.easeOut);
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Tutor'),
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(20),
          child: Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Text(widget.title,
                style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
          ),
        ),
      ),
      body: _error != null
          ? Center(child: Padding(padding: const EdgeInsets.all(24), child: Text(_error!)))
          : _starting
              ? const Center(child: CircularProgressIndicator())
              : Column(
                  children: [
                    Expanded(child: _transcript()),
                    _composer(),
                  ],
                ),
    );
  }

  Widget _transcript() {
    // A local welcome sits above the exchange; it never goes to the server.
    final showPartial = _partial != null;
    final count = 1 + _messages.length + (showPartial ? 1 : 0);

    return ListView.builder(
      controller: _scroll,
      padding: const EdgeInsets.all(16),
      itemCount: count,
      itemBuilder: (context, i) {
        if (i == 0) return const _Welcome();
        final index = i - 1;
        if (index < _messages.length) return _MessageBubble(message: _messages[index]);

        // The reply being streamed: a spinner until the first token lands.
        if ((_partial ?? '').isEmpty) return const _TypingBubble();
        return _MessageBubble(
          message: TutorMessage(role: 'assistant', content: _partial!, citations: _partialCitations),
        );
      },
    );
  }

  Widget _composer() {
    return SafeArea(
      top: false,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 8, 12, 8),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            Expanded(
              child: TextField(
                controller: _input,
                minLines: 1,
                maxLines: 4,
                textInputAction: TextInputAction.send,
                onSubmitted: (_) => _send(),
                decoration: InputDecoration(
                  hintText: 'Ask about this course…',
                  filled: true,
                  border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(24),
                    borderSide: BorderSide.none,
                  ),
                  contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
                ),
              ),
            ),
            const SizedBox(width: 8),
            IconButton.filled(
              onPressed: _sending ? null : _send,
              icon: const Icon(Icons.send_rounded),
            ),
          ],
        ),
      ),
    );
  }
}

class _Welcome extends StatelessWidget {
  const _Welcome();

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Theme.of(context).colorScheme.primary.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: [
            Icon(Icons.auto_awesome, size: 18, color: Theme.of(context).colorScheme.primary),
            const SizedBox(width: 6),
            const Text('Your study tutor', style: TextStyle(fontWeight: FontWeight.w700)),
          ]),
          const SizedBox(height: 6),
          Text(
            'Ask me to explain a topic, give another example, or check your understanding. '
            "I teach from this course's material — I won't hand over quiz answers.",
            style: TextStyle(color: Theme.of(context).hintColor, height: 1.35),
          ),
        ],
      ),
    );
  }
}

class _MessageBubble extends StatelessWidget {
  const _MessageBubble({required this.message});

  final TutorMessage message;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final isUser = message.isUser;

    return Align(
      alignment: isUser ? Alignment.centerRight : Alignment.centerLeft,
      child: Container(
        constraints: BoxConstraints(maxWidth: MediaQuery.of(context).size.width * 0.82),
        margin: const EdgeInsets.only(bottom: 12),
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
        decoration: BoxDecoration(
          color: isUser ? scheme.primary : scheme.surfaceContainerHighest,
          borderRadius: BorderRadius.only(
            topLeft: const Radius.circular(16),
            topRight: const Radius.circular(16),
            bottomLeft: Radius.circular(isUser ? 16 : 4),
            bottomRight: Radius.circular(isUser ? 4 : 16),
          ),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              message.content,
              style: TextStyle(
                color: isUser ? scheme.onPrimary : scheme.onSurface,
                height: 1.4,
              ),
            ),
            if (message.citations.isNotEmpty) ...[
              const SizedBox(height: 8),
              Wrap(
                spacing: 6,
                runSpacing: 6,
                children: [
                  for (final c in message.citations)
                    Chip(
                      label: Text(c.label, style: const TextStyle(fontSize: 11)),
                      visualDensity: VisualDensity.compact,
                      materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
                      padding: EdgeInsets.zero,
                      avatar: const Icon(Icons.menu_book_rounded, size: 14),
                    ),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _TypingBubble extends StatelessWidget {
  const _TypingBubble();

  @override
  Widget build(BuildContext context) {
    return Align(
      alignment: Alignment.centerLeft,
      child: Container(
        margin: const EdgeInsets.only(bottom: 12),
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        decoration: BoxDecoration(
          color: Theme.of(context).colorScheme.surfaceContainerHighest,
          borderRadius: BorderRadius.circular(16),
        ),
        child: const SizedBox(
          width: 20,
          height: 20,
          child: CircularProgressIndicator(strokeWidth: 2),
        ),
      ),
    );
  }
}
