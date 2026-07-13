import 'package:flutter/foundation.dart';

import 'api_client.dart';

/// Holds the signed-in state and drives the router between login and the app.
class AuthState extends ChangeNotifier {
  AuthState(this.api) {
    // A 401 from any request drops us back to the login screen.
    api.onUnauthorized = expired;
  }

  final ApiClient api;

  bool _ready = false;
  bool _signedIn = false;
  String? _userName;

  bool get ready => _ready; // finished restoring a saved token
  bool get signedIn => _signedIn;
  String? get userName => _userName;

  Future<void> restore() async {
    await api.restore();
    // Trust a saved token only if the backend still accepts it — otherwise a
    // stale/revoked token would strand the app on a broken dashboard.
    _signedIn = await api.tokenIsValid();
    _ready = true;
    notifyListeners();
  }

  Future<void> login(String email, String password) async {
    final user = await api.login(email, password);
    _userName = user['name'] as String?;
    _signedIn = true;
    notifyListeners();
  }

  /// Complete a B2B launch: exchange the ticket, sign in, and return the course
  /// to open (if the launch deep-linked to one).
  Future<String?> completeLaunch(String ticket) async {
    final body = await api.exchangeLaunch(ticket);
    _signedIn = true;
    notifyListeners();
    final link = body['deep_link'] as Map?;
    return link?['course_id'] as String?;
  }

  Future<void> logout() async {
    await api.logout();
    _signedIn = false;
    _userName = null;
    notifyListeners();
  }

  /// Called when a request 401s: drop to the login screen. Idempotent — several
  /// in-flight requests may 401 at once.
  void expired() {
    if (!_signedIn) return;
    _signedIn = false;
    _userName = null;
    notifyListeners();
  }
}
