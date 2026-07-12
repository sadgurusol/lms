import 'api_client.dart';

/// Accumulates a reading session's progress and flushes it to the server in
/// batches. The server merges idempotently (cumulative seconds win by GREATEST,
/// completion is monotonic), so re-sending or losing a batch is safe.
class ProgressTracker {
  ProgressTracker(this._api, this.publicationId);

  final ApiClient _api;
  final String publicationId;

  final Map<String, int> _seconds = {}; // cumulative seconds per node
  final Map<String, String> _state = {}; // node -> in_progress | completed
  final Set<String> _dirty = {}; // nodes changed since the last flush

  /// The node currently being read; time accrues here each tick.
  String? active;

  void tick() {
    final node = active;
    if (node == null) return;
    _seconds[node] = (_seconds[node] ?? 0) + 1;
    _dirty.add(node);
  }

  /// Move a node's state forward. Never backward — a node seen once stays seen,
  /// a node completed stays completed.
  void mark(String nodeId, String state) {
    final current = _state[nodeId];
    if (current == 'completed') return;
    if (current == state) return;
    if (current == 'in_progress' && state == 'not_started') return;
    _state[nodeId] = state;
    _dirty.add(nodeId);
  }

  /// Send everything changed since the last flush. Cumulative totals, so the
  /// server can safely GREATEST them.
  Future<void> flush() async {
    if (_dirty.isEmpty) return;
    final now = DateTime.now().toUtc().toIso8601String();
    final events = _dirty
        .map((id) => {
              'publication_id': publicationId,
              'node_id': id,
              'state': _state[id] ?? 'in_progress',
              'seconds_spent': _seconds[id] ?? 0,
              'client_updated_at': now,
            })
        .toList();
    // Clear optimistically; the merge is idempotent, so a failed flush simply
    // re-sends the same or larger totals next time the node changes.
    final pending = Set.of(_dirty);
    _dirty.clear();
    try {
      await _api.postProgress(events);
    } catch (_) {
      _dirty.addAll(pending); // retry on the next flush
    }
  }
}
