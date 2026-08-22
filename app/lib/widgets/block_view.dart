import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:video_player/video_player.dart';

import '../api_client.dart';
import '../models.dart';
import 'animated_reveal_block.dart';
import 'simulation_block.dart';

/// Read-only rendering of a content block — the Flutter counterpart of the
/// studio's BlockView. Same content, same shape, different toolkit.
class BlockView extends StatelessWidget {
  const BlockView({super.key, required this.block});

  final Block block;

  @override
  Widget build(BuildContext context) {
    switch (block.type) {
      case 'rich_text':
        return _PortableText(body: block.payload['body']);
      case 'callout':
        return _Callout(payload: block.payload);
      case 'embed':
        return _Embed(payload: block.payload);
      case 'image':
        return _Image(payload: block.payload);
      case 'attachment':
        return _Attachment(payload: block.payload);
      case 'video':
        return _Video(payload: block.payload);
      case 'animated_reveal':
        return AnimatedRevealBlock(payload: block.payload);
      case 'simulation':
        return SimulationBlock(payload: block.payload);
      case 'animation':
        // A generated animation is an mp4; reuse the video renderer's controls.
        return _Video(payload: {
          'src': block.payload['url'],
          'poster': block.payload['poster_url'],
        });
      case 'audio':
        return _Narration(payload: block.payload);
      default:
        return _Placeholder(label: '[${block.type} block]');
    }
  }
}

/// A video block. Plays HLS (from a provider) or a progressive mp4 (streamed
/// from our own bearer-protected endpoint in dev), both baked into the snapshot.
class _Video extends StatefulWidget {
  const _Video({required this.payload});

  final Map<String, dynamic> payload;

  @override
  State<_Video> createState() => _VideoState();
}

class _VideoState extends State<_Video> {
  VideoPlayerController? _controller;
  bool _failed = false;

  String? get _src => widget.payload['src'] as String?;
  String? get _poster => widget.payload['poster'] as String?;

  @override
  void initState() {
    super.initState();
    _init();
  }

  Future<void> _init() async {
    final src = _src;
    if (src == null) {
      setState(() => _failed = true);
      return;
    }
    // The local stream endpoint is bearer-protected; a public CDN ignores the
    // header, so sending it always is harmless.
    final headers = context.read<ApiClient>().authHeaders;
    final controller = VideoPlayerController.networkUrl(Uri.parse(src), httpHeaders: headers);
    try {
      await controller.initialize();
      if (!mounted) {
        await controller.dispose();
        return;
      }
      setState(() => _controller = controller);
    } catch (_) {
      await controller.dispose();
      if (mounted) setState(() => _failed = true);
    }
  }

  @override
  void dispose() {
    _controller?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (_failed) {
      return _Placeholder(label: 'Video unavailable.');
    }
    final controller = _controller;
    if (controller == null) {
      return AspectRatio(
        aspectRatio: 16 / 9,
        child: Container(
          decoration: BoxDecoration(color: Colors.black, borderRadius: BorderRadius.circular(10)),
          child: _poster == null
              ? const Center(child: CircularProgressIndicator())
              : ClipRRect(
                  borderRadius: BorderRadius.circular(10),
                  child: Image.network(_poster!, fit: BoxFit.cover, errorBuilder: (_, _, _) => const SizedBox()),
                ),
        ),
      );
    }

    return ClipRRect(
      borderRadius: BorderRadius.circular(10),
      child: AspectRatio(
        aspectRatio: controller.value.aspectRatio == 0 ? 16 / 9 : controller.value.aspectRatio,
        child: Stack(
          alignment: Alignment.center,
          children: [
            VideoPlayer(controller),
            _PlayPause(controller: controller),
            Positioned(
              left: 0,
              right: 0,
              bottom: 0,
              child: VideoProgressIndicator(controller, allowScrubbing: true),
            ),
          ],
        ),
      ),
    );
  }
}

class _PlayPause extends StatelessWidget {
  const _PlayPause({required this.controller});

  final VideoPlayerController controller;

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<VideoPlayerValue>(
      valueListenable: controller,
      builder: (context, value, _) {
        return GestureDetector(
          onTap: () => value.isPlaying ? controller.pause() : controller.play(),
          behavior: HitTestBehavior.opaque,
          child: AnimatedOpacity(
            opacity: value.isPlaying ? 0 : 1,
            duration: const Duration(milliseconds: 200),
            child: Container(
              color: Colors.black26,
              child: const Center(
                child: Icon(Icons.play_circle_fill, size: 56, color: Colors.white70),
              ),
            ),
          ),
        );
      },
    );
  }
}

class _Image extends StatelessWidget {
  const _Image({required this.payload});

  final Map<String, dynamic> payload;

  @override
  Widget build(BuildContext context) {
    final url = payload['url'] as String?;
    final alt = payload['alt'] as String? ?? '';
    final caption = payload['caption'] as String?;
    if (url == null) {
      return Text('Image unavailable.', style: TextStyle(color: Theme.of(context).hintColor));
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        ClipRRect(
          borderRadius: BorderRadius.circular(10),
          child: Image.network(
            url,
            fit: BoxFit.contain,
            semanticLabel: alt,
            errorBuilder: (_, _, _) => _Placeholder(label: alt.isEmpty ? 'Image' : alt),
            loadingBuilder: (context, child, progress) => progress == null
                ? child
                : const SizedBox(height: 160, child: Center(child: CircularProgressIndicator())),
          ),
        ),
        if (caption != null && caption.isNotEmpty)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text(caption, style: TextStyle(color: Theme.of(context).hintColor, fontSize: 13)),
          ),
      ],
    );
  }
}

class _Attachment extends StatelessWidget {
  const _Attachment({required this.payload});

  final Map<String, dynamic> payload;

  @override
  Widget build(BuildContext context) {
    final filename = payload['filename'] as String? ?? 'Attachment';
    final bytes = payload['size_bytes'] as num?;
    final size = bytes == null ? null : '${(bytes / 1024).round()} KB';
    return Card(
      margin: EdgeInsets.zero,
      child: ListTile(
        leading: const Icon(Icons.attachment_rounded),
        title: Text(filename, maxLines: 1, overflow: TextOverflow.ellipsis),
        subtitle: size == null ? null : Text(size),
        trailing: const Icon(Icons.download_rounded),
      ),
    );
  }
}

/// Portable Text: an array of blocks, each with a style and spans (with marks).
class _PortableText extends StatelessWidget {
  const _PortableText({required this.body});

  final dynamic body;

  @override
  Widget build(BuildContext context) {
    if (body is! List || (body as List).isEmpty) {
      return Text('Empty.', style: TextStyle(color: Theme.of(context).hintColor, fontStyle: FontStyle.italic));
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        for (final blk in (body as List).whereType<Map>())
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: _ptBlock(context, blk.cast<String, dynamic>()),
          ),
      ],
    );
  }

  Widget _ptBlock(BuildContext context, Map<String, dynamic> blk) {
    final theme = Theme.of(context).textTheme;
    final spans = _spans(context, blk['children']);

    if (blk['listItem'] != null) {
      return Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [const Text('•  '), Expanded(child: Text.rich(TextSpan(children: spans)))],
      );
    }

    TextStyle? style;
    switch (blk['style'] as String?) {
      case 'h2':
        style = theme.titleLarge;
      case 'h3':
        style = theme.titleMedium;
      case 'h4':
        style = theme.titleSmall;
      case 'blockquote':
        return Container(
          padding: const EdgeInsets.only(left: 12),
          decoration: BoxDecoration(
            border: Border(left: BorderSide(color: Theme.of(context).dividerColor, width: 3)),
          ),
          child: Text.rich(TextSpan(children: spans),
              style: const TextStyle(fontStyle: FontStyle.italic)),
        );
      case 'code':
        return Container(
          width: double.infinity,
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: Theme.of(context).colorScheme.surfaceContainerHighest,
            borderRadius: BorderRadius.circular(6),
          ),
          child: Text.rich(TextSpan(children: spans), style: const TextStyle(fontFamily: 'monospace')),
        );
      default:
        style = theme.bodyLarge;
    }

    return Text.rich(TextSpan(children: spans), style: style);
  }

  List<TextSpan> _spans(BuildContext context, dynamic children) {
    if (children is! List) return const [];
    return children.whereType<Map>().map((raw) {
      final span = raw.cast<String, dynamic>();
      final marks = (span['marks'] as List?)?.cast<String>() ?? const [];
      return TextSpan(
        text: span['text'] as String? ?? '',
        style: TextStyle(
          fontWeight: marks.contains('strong') ? FontWeight.bold : null,
          fontStyle: marks.contains('em') ? FontStyle.italic : null,
          fontFamily: marks.contains('code') ? 'monospace' : null,
        ),
      );
    }).toList();
  }
}

class _Callout extends StatelessWidget {
  const _Callout({required this.payload});

  final Map<String, dynamic> payload;

  static const _tones = {
    'info': Color(0xFF0EA5E9),
    'tip': Color(0xFF10B981),
    'warning': Color(0xFFF59E0B),
    'danger': Color(0xFFEF4444),
    'example': Color(0xFF8B5CF6),
  };

  @override
  Widget build(BuildContext context) {
    final variant = (payload['variant'] as String?) ?? 'info';
    final tone = _tones[variant] ?? _tones['info']!;
    final title = payload['title'] as String?;

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: tone.withValues(alpha: 0.08),
        border: Border.all(color: tone.withValues(alpha: 0.4)),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            (title ?? variant).toUpperCase(),
            style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: tone, letterSpacing: 0.5),
          ),
          const SizedBox(height: 4),
          _PortableText(body: payload['body']),
        ],
      ),
    );
  }
}

class _Embed extends StatelessWidget {
  const _Embed({required this.payload});

  final Map<String, dynamic> payload;

  @override
  Widget build(BuildContext context) {
    final provider = (payload['provider'] as String?) ?? 'embed';
    final url = (payload['url'] as String?) ?? '';
    final title = payload['title'] as String?;

    // Inline players are a later iteration; show a clear affordance for now.
    return Card(
      margin: EdgeInsets.zero,
      child: ListTile(
        leading: const Icon(Icons.play_circle_outline),
        title: Text(title ?? '$provider content'),
        subtitle: Text(url, maxLines: 1, overflow: TextOverflow.ellipsis),
      ),
    );
  }
}

/// Step narration: the player auto-plays the clip, so here we just surface the
/// transcript (with a speaker cue) for readers/accessibility.
class _Narration extends StatelessWidget {
  const _Narration({required this.payload});

  final Map<String, dynamic> payload;

  @override
  Widget build(BuildContext context) {
    final transcript = (payload['transcript'] as String?)?.trim() ?? '';
    if (transcript.isEmpty) return const SizedBox.shrink();
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(Icons.volume_up_outlined, size: 18, color: Theme.of(context).hintColor),
        const SizedBox(width: 8),
        Expanded(child: Text(transcript, style: TextStyle(color: Theme.of(context).hintColor))),
      ],
    );
  }
}

class _Placeholder extends StatelessWidget {
  const _Placeholder({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        border: Border.all(color: Theme.of(context).dividerColor, style: BorderStyle.solid),
        borderRadius: BorderRadius.circular(6),
      ),
      child: Text(label, style: TextStyle(color: Theme.of(context).hintColor)),
    );
  }
}
