import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../api_client.dart';
import '../models.dart';
import '../responsive.dart';

/// The learner's past tutor chats for a course, plus a way to start a new one.
/// Reached from the ✨ tutor icon on the course screen.
class TutorConversationsScreen extends StatefulWidget {
  const TutorConversationsScreen({super.key, required this.courseId, required this.title});

  final String courseId;
  final String title;

  @override
  State<TutorConversationsScreen> createState() => _TutorConversationsScreenState();
}

class _TutorConversationsScreenState extends State<TutorConversationsScreen> {
  late Future<List<TutorConversationSummary>> _future;

  @override
  void initState() {
    super.initState();
    _future = context.read<ApiClient>().tutorConversations(widget.courseId);
  }

  void _reload() => setState(() => _future = context.read<ApiClient>().tutorConversations(widget.courseId));

  String get _titleParam => Uri.encodeComponent(widget.title);

  Future<void> _open(String? conversationId) async {
    final query = conversationId == null ? '?title=$_titleParam' : '?title=$_titleParam&conversation=$conversationId';
    // Reload the list on return so a new/updated chat shows.
    await context.push('/courses/${widget.courseId}/tutor/chat$query');
    if (mounted) _reload();
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
            child: Text(widget.title, style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
          ),
        ),
      ),
      body: RefreshIndicator(
        onRefresh: () async => _reload(),
        child: FutureBuilder<List<TutorConversationSummary>>(
          future: _future,
          builder: (context, snapshot) {
            if (snapshot.connectionState == ConnectionState.waiting) {
              return const Center(child: CircularProgressIndicator());
            }
            final conversations = snapshot.data ?? const <TutorConversationSummary>[];
            return MaxWidth(
              maxWidth: 720,
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  _NewChatTile(onTap: () => _open(null)),
                  if (snapshot.hasError)
                    Padding(
                      padding: const EdgeInsets.only(top: 16),
                      child: Text(
                        snapshot.error is ApiException
                            ? (snapshot.error as ApiException).message
                            : 'Could not load your conversations.',
                        style: TextStyle(color: Theme.of(context).hintColor),
                      ),
                    )
                  else if (conversations.isEmpty)
                    Padding(
                      padding: const EdgeInsets.only(top: 32),
                      child: Center(
                        child: Text('No past chats yet.', style: TextStyle(color: Theme.of(context).hintColor)),
                      ),
                    )
                  else ...[
                    const SizedBox(height: 20),
                    Text('Recent chats', style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
                    const SizedBox(height: 8),
                    for (final c in conversations) _ConversationTile(conversation: c, onTap: () => _open(c.id)),
                  ],
                ],
              ),
            );
          },
        ),
      ),
    );
  }
}

class _NewChatTile extends StatelessWidget {
  const _NewChatTile({required this.onTap});

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Card(
      color: scheme.primaryContainer,
      margin: EdgeInsets.zero,
      child: ListTile(
        onTap: onTap,
        leading: Icon(Icons.auto_awesome, color: scheme.onPrimaryContainer),
        title: Text(
          'New chat',
          style: TextStyle(fontWeight: FontWeight.w700, color: scheme.onPrimaryContainer),
        ),
        subtitle: Text(
          'Ask the tutor about this course',
          style: TextStyle(color: scheme.onPrimaryContainer.withValues(alpha: 0.8)),
        ),
        trailing: Icon(Icons.add, color: scheme.onPrimaryContainer),
      ),
    );
  }
}

class _ConversationTile extends StatelessWidget {
  const _ConversationTile({required this.conversation, required this.onTap});

  final TutorConversationSummary conversation;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: ListTile(
        onTap: onTap,
        leading: const Icon(Icons.chat_bubble_outline_rounded),
        title: Text(conversation.title ?? 'Conversation', maxLines: 1, overflow: TextOverflow.ellipsis),
        subtitle: conversation.createdAt == null ? null : Text(_relativeTime(conversation.createdAt!)),
        trailing: const Icon(Icons.chevron_right_rounded),
      ),
    );
  }

  String _relativeTime(DateTime time) {
    final diff = DateTime.now().difference(time);
    if (diff.inMinutes < 1) return 'just now';
    if (diff.inMinutes < 60) return '${diff.inMinutes}m ago';
    if (diff.inHours < 24) return '${diff.inHours}h ago';
    if (diff.inDays < 7) return '${diff.inDays}d ago';
    return '${time.year}-${time.month.toString().padLeft(2, '0')}-${time.day.toString().padLeft(2, '0')}';
  }
}
