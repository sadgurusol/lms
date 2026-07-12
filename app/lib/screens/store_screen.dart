import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../api_client.dart';
import '../models.dart';

/// The storefront: browse products and open a checkout. Payment happens in the
/// browser; access arrives via the payment webhook, so the learner pulls to
/// refresh (here and on the Courses tab) once they're done.
class StoreScreen extends StatefulWidget {
  const StoreScreen({super.key});

  @override
  State<StoreScreen> createState() => _StoreScreenState();
}

class _StoreScreenState extends State<StoreScreen> {
  late Future<List<CatalogProduct>> _future;
  String? _busyPlan;

  @override
  void initState() {
    super.initState();
    _future = context.read<ApiClient>().catalog();
  }

  void _reload() => setState(() => _future = context.read<ApiClient>().catalog());

  Future<void> _checkout(CatalogPlan plan) async {
    setState(() => _busyPlan = plan.code);
    final messenger = ScaffoldMessenger.of(context);
    try {
      final url = await context.read<ApiClient>().startCheckout(plan);
      final launched = await launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
      if (!launched) throw ApiException('Could not open the checkout page.');
      messenger.showSnackBar(const SnackBar(
        content: Text('Finish paying in your browser, then pull down to refresh.'),
      ));
    } on ApiException catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e.message)));
    } catch (_) {
      messenger.showSnackBar(const SnackBar(content: Text('Could not start checkout. Please try again.')));
    } finally {
      if (mounted) setState(() => _busyPlan = null);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Explore courses')),
      body: RefreshIndicator(
        onRefresh: () async => _reload(),
        child: FutureBuilder<List<CatalogProduct>>(
          future: _future,
          builder: (context, snapshot) {
            if (snapshot.connectionState == ConnectionState.waiting) {
              return const Center(child: CircularProgressIndicator());
            }
            if (snapshot.hasError) {
              return _CenteredScroll(child: Text(
                snapshot.error is ApiException ? (snapshot.error as ApiException).message : 'Something went wrong.',
              ));
            }
            final products = snapshot.data ?? const [];
            if (products.isEmpty) {
              return const _CenteredScroll(child: Text('Nothing is on sale right now.'));
            }
            return ListView.separated(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
              itemCount: products.length,
              separatorBuilder: (_, _) => const SizedBox(height: 14),
              itemBuilder: (context, i) => _ProductCard(
                product: products[i],
                busyPlan: _busyPlan,
                onCheckout: _checkout,
              ),
            );
          },
        ),
      ),
    );
  }
}

class _ProductCard extends StatelessWidget {
  const _ProductCard({required this.product, required this.busyPlan, required this.onCheckout});

  final CatalogProduct product;
  final String? busyPlan;
  final ValueChanged<CatalogPlan> onCheckout;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(product.name, style: Theme.of(context).textTheme.titleMedium),
                ),
                if (product.owned)
                  Chip(
                    label: const Text('Owned'),
                    visualDensity: VisualDensity.compact,
                    backgroundColor: scheme.primaryContainer,
                  ),
              ],
            ),
            if (product.courses.isNotEmpty) ...[
              const SizedBox(height: 8),
              Text('Includes', style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12)),
              const SizedBox(height: 4),
              for (final course in product.courses)
                Padding(
                  padding: const EdgeInsets.only(bottom: 2),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Icon(Icons.check_rounded, size: 16),
                      const SizedBox(width: 6),
                      Expanded(child: Text(course.title)),
                    ],
                  ),
                ),
            ],
            const SizedBox(height: 12),
            if (product.owned)
              Text('You have access to this.',
                  style: TextStyle(color: scheme.primary, fontWeight: FontWeight.w600))
            else
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  for (final plan in product.plans)
                    FilledButton(
                      onPressed: busyPlan == null ? () => onCheckout(plan) : null,
                      child: busyPlan == plan.code
                          ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2))
                          : Text(_planLabel(plan)),
                    ),
                ],
              ),
          ],
        ),
      ),
    );
  }

  String _planLabel(CatalogPlan plan) {
    final price = '${plan.priceLabel}${plan.intervalLabel}';
    if (plan.trialDays > 0) return '$price · ${plan.trialDays}-day trial';
    return plan.isSubscription ? 'Subscribe · $price' : 'Buy · $price';
  }
}

class _CenteredScroll extends StatelessWidget {
  const _CenteredScroll({required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    return ListView(
      children: [
        const SizedBox(height: 140),
        Center(child: Padding(padding: const EdgeInsets.all(24), child: child)),
      ],
    );
  }
}
