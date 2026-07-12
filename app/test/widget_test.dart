import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:learner_app/screens/login_screen.dart';

void main() {
  testWidgets('login screen renders its fields', (tester) async {
    await tester.pumpWidget(const MaterialApp(home: LoginScreen()));
    expect(find.text('Sign in'), findsWidgets);
    expect(find.byType(TextField), findsNWidgets(2));
  });
}
