import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import 'config.dart';
import 'models.dart';

/// A thin wrapper over the learner API. Holds the bearer token, attaches it to
/// every request, and turns non-2xx into a readable [ApiException].
class ApiClient {
  ApiClient({FlutterSecureStorage? storage})
      : _storage = storage ?? const FlutterSecureStorage() {
    _dio = Dio(BaseOptions(
      baseUrl: apiBaseUrl,
      headers: {'Accept': 'application/json'},
      // Inspect every status ourselves (see _throwIfError) rather than letting
      // Dio throw a raw DioException — a 5xx should still surface a clean message.
      validateStatus: (_) => true,
    ));
    _dio.interceptors.add(InterceptorsWrapper(onRequest: (options, handler) {
      if (_token != null) options.headers['Authorization'] = 'Bearer $_token';
      handler.next(options);
    }));
  }

  static const _tokenKey = 'access_token';

  late final Dio _dio;
  final FlutterSecureStorage _storage;
  String? _token;

  bool get isAuthenticated => _token != null;

  /// Auth header for playing a locally-streamed video, which is served by our
  /// own (bearer-protected) endpoint rather than a public CDN.
  Map<String, String> get authHeaders =>
      _token == null ? const {} : {'Authorization': 'Bearer $_token'};

  /// Load a token saved from a previous session.
  Future<void> restore() async {
    _token = await _storage.read(key: _tokenKey);
  }

  Future<Map<String, dynamic>> login(String email, String password) async {
    final res = await _dio.post('/auth/token', data: {
      'email': email,
      'password': password,
      'device_name': 'learner-app',
    });
    _throwIfError(res, fallback: 'Sign in failed');

    final body = res.data as Map<String, dynamic>;
    _token = body['access_token'] as String;
    await _storage.write(key: _tokenKey, value: _token);
    return (body['user'] as Map).cast<String, dynamic>();
  }

  /// Exchange a one-time launch ticket (from a B2B deep link) for a token.
  /// Returns the launch context, including any course to open. No password path.
  Future<Map<String, dynamic>> exchangeLaunch(String ticket) async {
    final res = await _dio.post('/auth/launch/exchange', data: {'ticket': ticket});
    _throwIfError(res, fallback: 'This launch link is invalid or has expired.');

    final body = res.data as Map<String, dynamic>;
    _token = body['access_token'] as String;
    await _storage.write(key: _tokenKey, value: _token);
    return body;
  }

  Future<void> logout() async {
    try {
      await _dio.post('/auth/logout');
    } finally {
      _token = null;
      await _storage.delete(key: _tokenKey);
    }
  }

  Future<DashboardData> dashboard() async {
    final res = await _dio.get('/me/dashboard');
    _throwIfError(res, fallback: 'Could not load your dashboard');
    return DashboardData.fromJson((res.data as Map).cast<String, dynamic>());
  }

  Future<List<CourseSummary>> courses() async {
    final res = await _dio.get('/me/courses');
    _throwIfError(res, fallback: 'Could not load your courses');
    final data = (res.data['data'] as List).cast<Map>();
    return data.map((c) => CourseSummary.fromJson(c.cast<String, dynamic>())).toList();
  }

  Future<CourseContent> courseContent(String courseId) async {
    final res = await _dio.get('/me/courses/$courseId/content');
    _throwIfError(res, fallback: 'Could not open this course');
    return CourseContent.fromJson((res.data as Map).cast<String, dynamic>());
  }

  /// The storefront: products the learner can buy.
  Future<List<CatalogProduct>> catalog() async {
    final res = await _dio.get('/me/catalog');
    _throwIfError(res, fallback: 'Could not load the catalog');
    final data = (res.data['data'] as List).cast<Map>();
    return data.map((p) => CatalogProduct.fromJson(p.cast<String, dynamic>())).toList();
  }

  /// Open a checkout for a plan and return the payment URL. Access is granted by
  /// the payment webhook after the learner pays, not by this call.
  Future<String> startCheckout(CatalogPlan plan) async {
    final path = plan.isSubscription ? '/me/subscriptions' : '/me/purchases';
    final res = await _dio.post(path, data: {'plan_code': plan.code});
    _throwIfError(res, fallback: 'Could not start checkout');
    return res.data['checkout_url'] as String;
  }

  /// Begin an AI-tutor conversation for a course; returns its id.
  Future<String> startTutorConversation(String courseId) async {
    final res = await _dio.post('/me/courses/$courseId/tutor/conversations');
    _throwIfError(res, fallback: 'Could not start the tutor');
    return res.data['data']['id'] as String;
  }

  /// Ask the tutor a question and get its reply.
  Future<TutorMessage> sendTutorMessage(
    String conversationId,
    String content, {
    String? focusNodeId,
  }) async {
    final res = await _dio.post('/me/tutor/conversations/$conversationId/messages', data: {
      'content': content,
      'focus_node_id': ?focusNodeId,
    });
    _throwIfError(res, fallback: 'The tutor could not reply');
    return TutorMessage.fromJson((res.data['data'] as Map).cast<String, dynamic>());
  }

  /// Ask the tutor and stream the reply as it is written. Yields a [TutorDelta]
  /// per token, then a final [TutorDone] carrying the citations.
  Stream<TutorEvent> streamTutorMessage(
    String conversationId,
    String content, {
    String? focusNodeId,
  }) async* {
    final res = await _dio.post<ResponseBody>(
      '/me/tutor/conversations/$conversationId/stream',
      data: {'content': content, 'focus_node_id': ?focusNodeId},
      options: Options(responseType: ResponseType.stream, headers: {'Accept': 'text/event-stream'}),
    );

    final body = res.data;
    if (body == null) throw ApiException('The tutor could not reply');

    // A non-200 (disabled by the institution, usage limit, revoked access) is a
    // JSON error, not an SSE stream — read it and surface its message.
    if (res.statusCode != 200) {
      final raw = await utf8.decodeStream(body.stream);
      String message = 'The tutor could not reply';
      try {
        final json = jsonDecode(raw);
        if (json is Map && json['message'] is String) message = json['message'] as String;
      } catch (_) {}
      throw ApiException(message, unauthorized: res.statusCode == 401);
    }

    // Reassemble SSE frames (blank-line separated) from the byte stream.
    var buffer = '';
    await for (final chunk in body.stream) {
      buffer += utf8.decode(chunk, allowMalformed: true);
      var split = buffer.indexOf('\n\n');
      while (split != -1) {
        final frame = buffer.substring(0, split);
        buffer = buffer.substring(split + 2);
        final event = _parseSseFrame(frame);
        if (event != null) yield event;
        split = buffer.indexOf('\n\n');
      }
    }
  }

  TutorEvent? _parseSseFrame(String frame) {
    var name = 'message';
    String? data;
    for (final line in frame.split('\n')) {
      if (line.startsWith('event:')) name = line.substring(6).trim();
      if (line.startsWith('data:')) data = line.substring(5).trim();
    }
    if (data == null) return null;

    final json = jsonDecode(data) as Map<String, dynamic>;
    switch (name) {
      case 'delta':
        return TutorDelta(json['delta'] as String? ?? '');
      case 'done':
        return TutorDone(
          ((json['citations'] as List?) ?? const [])
              .map((c) => TutorCitation.fromJson((c as Map).cast<String, dynamic>()))
              .toList(),
        );
      case 'error':
        throw ApiException(json['message'] as String? ?? 'The tutor is unavailable right now.');
      default:
        return null;
    }
  }

  /// Flush a batch of progress events. Fire-and-forget from the caller's view —
  /// the server merges idempotently, so a lost or retried batch is harmless.
  Future<void> postProgress(List<Map<String, dynamic>> events) async {
    if (events.isEmpty) return;
    final res = await _dio.post('/me/progress', data: {'events': events});
    _throwIfError(res, fallback: 'Could not save progress');
  }

  Future<List<AssessmentSummary>> courseAssessments(String courseId) async {
    final res = await _dio.get('/me/courses/$courseId/assessments');
    _throwIfError(res, fallback: 'Could not load quizzes');
    final data = (res.data['data'] as List).cast<Map>();
    return data.map((a) => AssessmentSummary.fromJson(a.cast<String, dynamic>())).toList();
  }

  Future<Attempt> startAttempt(String assessmentId) async {
    final res = await _dio.post('/me/assessments/$assessmentId/attempts');
    _throwIfError(res, fallback: 'Could not start this quiz');
    return Attempt.fromJson((res.data['data'] as Map).cast<String, dynamic>());
  }

  Future<Attempt> attempt(String attemptId) async {
    final res = await _dio.get('/me/attempts/$attemptId');
    _throwIfError(res, fallback: 'Could not open this attempt');
    return Attempt.fromJson((res.data['data'] as Map).cast<String, dynamic>());
  }

  Future<void> answer(String attemptId, String assessmentQuestionId, Map<String, dynamic> response) async {
    final res = await _dio.post('/me/attempts/$attemptId/answers', data: {
      'assessment_question_id': assessmentQuestionId,
      'response': response,
    });
    _throwIfError(res, fallback: 'Could not save your answer');
  }

  Future<Attempt> submitAttempt(String attemptId) async {
    final res = await _dio.post('/me/attempts/$attemptId/submit');
    _throwIfError(res, fallback: 'Could not submit this quiz');
    return Attempt.fromJson((res.data['data'] as Map).cast<String, dynamic>());
  }

  void _throwIfError(Response res, {required String fallback}) {
    final code = res.statusCode ?? 0;
    if (code >= 200 && code < 300) return;

    if (code == 401) throw ApiException('Your session has expired.', unauthorized: true);

    final data = res.data;
    final message = data is Map && data['message'] is String ? data['message'] as String : fallback;
    throw ApiException(message);
  }
}

class ApiException implements Exception {
  ApiException(this.message, {this.unauthorized = false});

  final String message;
  final bool unauthorized;

  @override
  String toString() => message;
}
