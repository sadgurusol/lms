import 'package:flutter/widgets.dart';

/// Width at/above which we treat the screen as a tablet / large display and
/// switch to the roomier layouts (side navigation, grids, multi-column).
const double kTabletBreakpoint = 720;

extension ResponsiveContext on BuildContext {
  double get screenWidth => MediaQuery.sizeOf(this).width;

  /// True on tablets and larger windows.
  bool get isTablet => screenWidth >= kTabletBreakpoint;
}

/// Centres content and caps its width on large screens, so a phone layout
/// becomes a comfortable, readable version on a tablet instead of stretching
/// edge to edge. Grids and multi-column areas pass a wider [maxWidth].
class MaxWidth extends StatelessWidget {
  const MaxWidth({super.key, required this.child, this.maxWidth = 760});

  final Widget child;
  final double maxWidth;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: ConstrainedBox(
        constraints: BoxConstraints(maxWidth: maxWidth),
        child: child,
      ),
    );
  }
}
