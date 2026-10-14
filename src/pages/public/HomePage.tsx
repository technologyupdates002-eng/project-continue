import { Link } from '@/lib/router-compat';
import { useQuery } from '@tanstack/react-query';
import {
  Heart, Users, Wallet, Building2, ShieldCheck,
  ArrowRight,
} from 'lucide-react';
import { Card, Badge, Button, LoadingState, ErrorState, SkeletonCard } from '@/components/ui';
import { queryKeys } from '@/lib/query';
import { mockPublic } from '@/api/mock';
import { formatDate, initials, classNames } from '@/lib/format';
import type { WebsiteContent, LeadershipPerson } from '@/types';

const HERO_IMAGE = '/images/image%20copy%202.png';
const ABOUT_IMAGE = '/images/image%20copy.png';

const stats = [
  { label: 'Members', value: '512+', icon: Users, color: 'primary' as const },
  { label: 'Contributions', value: 'KES 4.2M+', icon: Wallet, color: 'success' as const },
  { label: 'Hospitals', value: '6', icon: Building2, color: 'secondary' as const },
  { label: 'Transparency', value: '100%', icon: ShieldCheck, color: 'accent' as const },
];

export default function HomePage() {
  const { data: settings } = useQuery({
    queryKey: queryKeys.publicSettings,
    queryFn: () => mockPublic.getSettings(),
  });

  const { data: newsData, isLoading: newsLoading, isError: newsError } = useQuery({
    queryKey: queryKeys.publicContent('news', 1),
    queryFn: () => mockPublic.getContent('news', 1),
  });

  const { data: eventsData, isLoading: eventsLoading, isError: eventsError } = useQuery({
    queryKey: queryKeys.publicContent('events', 1),
    queryFn: () => mockPublic.getContent('events', 1),
  });

  const { data: leadership, isLoading: leadershipLoading, isError: leadershipError } = useQuery({
    queryKey: queryKeys.publicLeadership,
    queryFn: () => mockPublic.getLeadership(),
  });

  const newsItems = newsData?.data?.slice(0, 3) ?? [];
  const eventItems = eventsData?.data?.slice(0, 3) ?? [];
  const leaders = (leadership ?? []).slice(0, 4);

  return (
    <div>
      {/* Hero Section — N26 saturated teal band */}
      <section className="relative min-h-[520px] sm:min-h-[560px] flex items-center bg-primary-500">
        <div className="absolute inset-0">
          <img src={HERO_IMAGE} alt="Healthcare workers" className="h-full w-full object-cover" />
          <div className="absolute inset-0 bg-gradient-to-r from-primary-950/95 via-primary-800/85 to-primary-600/50" />
        </div>
        <div className="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16 sm:py-24">
          <div className="max-w-2xl">
            <div className="inline-flex items-center gap-2 rounded-full bg-white/15 px-3 py-1 text-xs font-medium text-white backdrop-blur-sm mb-5">
              <Heart size={14} className="text-primary-200" />
              {settings?.organization_name ?? 'Kirinyaga Healthcare Workers Welfare'}
            </div>
            <h1 className="text-3xl sm:text-4xl lg:text-5xl font-bold text-white leading-tight tracking-tight text-balance">
              Supporting those who care for our community
            </h1>
            <p className="mt-4 text-base sm:text-lg text-primary-50/90 leading-relaxed max-w-xl">
              {settings?.tagline ?? 'A welfare organization dedicated to healthcare workers in Kirinyaga County, providing financial security, emergency support, and community.'}
            </p>
            <div className="mt-8 flex flex-col sm:flex-row gap-3">
              <Link to="/public/website/contact">
                <Button size="lg" className="w-full sm:w-auto bg-white text-primary-700 hover:bg-primary-50">
                  Become a Member
                  <ArrowRight size={18} />
                </Button>
              </Link>
              <Link to="/login">
                <Button size="lg" variant="secondary" className="w-full sm:w-auto border-white/20 bg-transparent text-white hover:bg-white/10">
                  Member Login
                </Button>
              </Link>
            </div>
          </div>
        </div>
      </section>

      {/* Stats Section */}
      <section className="bg-white border-b border-neutral-100">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 sm:py-12">
          <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6">
            {stats.map((stat) => {
              const Icon = stat.icon;
              const colorMap = {
                primary: 'bg-primary-50 text-primary-700',
                secondary: 'bg-secondary-50 text-secondary-700',
                accent: 'bg-accent-50 text-accent-700',
                success: 'bg-success-50 text-success-700',
              };
              return (
                <Card key={stat.label} className="p-4 sm:p-5 text-center sm:text-left">
                  <div className="flex items-center justify-center sm:justify-start gap-3">
                    <div className={classNames('flex-shrink-0 w-10 h-10 rounded-lg flex items-center justify-center', colorMap[stat.color])}>
                      <Icon size={20} />
                    </div>
                    <div className="min-w-0">
                      <p className="text-xl sm:text-2xl font-bold text-neutral-900 tracking-tight">{stat.value}</p>
                      <p className="text-xs sm:text-sm text-neutral-500">{stat.label}</p>
                    </div>
                  </div>
                </Card>
              );
            })}
          </div>
        </div>
      </section>

      {/* About Preview */}
      <section className="bg-neutral-50">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-14 sm:py-20">
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-10 lg:gap-16 items-center">
            <div className="order-2 lg:order-1">
              <Badge className="bg-primary-50 text-primary-700 mb-4">About Us</Badge>
              <h2 className="text-2xl sm:text-3xl font-bold text-neutral-900 tracking-tight text-balance">
                A community built on care and mutual support
              </h2>
              <p className="mt-4 text-sm sm:text-base text-neutral-600 leading-relaxed">
                {settings?.about ?? 'KHWWC is a welfare organization dedicated to supporting healthcare workers in Kirinyaga County.'}
              </p>
              <div className="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div className="flex items-start gap-3">
                  <div className="flex-shrink-0 w-9 h-9 rounded-lg bg-primary-50 text-primary-700 flex items-center justify-center">
                    <ShieldCheck size={18} />
                  </div>
                  <div>
                    <p className="text-sm font-semibold text-neutral-900">Financial Security</p>
                    <p className="text-xs text-neutral-500 mt-0.5">Emergency support when you need it most</p>
                  </div>
                </div>
                <div className="flex items-start gap-3">
                  <div className="flex-shrink-0 w-9 h-9 rounded-lg bg-success-50 text-success-700 flex items-center justify-center">
                    <Users size={18} />
                  </div>
                  <div>
                    <p className="text-sm font-semibold text-neutral-900">500+ Members</p>
                    <p className="text-xs text-neutral-500 mt-0.5">A growing community across 6 hospitals</p>
                  </div>
                </div>
              </div>
              <Link to="/public/website/content/about" className="inline-flex items-center gap-1.5 mt-6 text-sm font-semibold text-primary-600 hover:text-primary-700">
                Learn more about us
                <ArrowRight size={16} />
              </Link>
            </div>
            <div className="order-1 lg:order-2">
              <div className="relative rounded-n26-card overflow-hidden shadow-float">
                <img src={ABOUT_IMAGE} alt="Healthcare community" className="w-full h-64 sm:h-80 object-cover" />
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* Featured News */}
      <section className="bg-white">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-14 sm:py-20">
          <div className="flex items-end justify-between mb-8">
            <div>
              <Badge className="bg-primary-50 text-primary-700 mb-3">Latest News</Badge>
              <h2 className="text-2xl sm:text-3xl font-bold text-neutral-900 tracking-tight">Featured updates</h2>
            </div>
            <Link to="/public/website/content/news" className="hidden sm:inline-flex items-center gap-1.5 text-sm font-semibold text-primary-600 hover:text-primary-700">
              View all news
              <ArrowRight size={16} />
            </Link>
          </div>

          {newsLoading ? (
            <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
              <SkeletonCard /><SkeletonCard /><SkeletonCard />
            </div>
          ) : newsError ? (
            <ErrorState message="Failed to load news. Please try again later." />
          ) : newsItems.length === 0 ? (
            <Card className="p-8 text-center text-sm text-neutral-500">No news articles published yet.</Card>
          ) : (
            <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
              {newsItems.map((article: WebsiteContent) => (
                <Link key={article.id} to={`/public/website/content/news/${article.slug}`}>
                  <Card elevated className="h-full p-5 flex flex-col">
                    <div className="flex items-center gap-2 mb-3">
                      {article.image ? (
                        <img src={article.image} alt={article.title} className="w-full h-40 rounded-lg object-cover mb-3" />
                      ) : (
                        <Badge className="bg-primary-50 text-primary-700">{article.author}</Badge>
                      )}
                    </div>
                    <p className="text-xs text-neutral-400 mb-2">{formatDate(article.date, 'long')}</p>
                    <h3 className="text-base font-semibold text-neutral-900 leading-snug line-clamp-2">{article.title}</h3>
                    <p className="mt-2 text-sm text-neutral-500 line-clamp-3 flex-1">{article.excerpt}</p>
                    <span className="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-primary-600">
                      Read more <ArrowRight size={14} />
                    </span>
                  </Card>
                </Link>
              ))}
            </div>
          )}
          <div className="mt-6 text-center sm:hidden">
            <Link to="/public/website/content/news">
              <Button variant="secondary">View all news</Button>
            </Link>
          </div>
        </div>
      </section>

      {/* Upcoming Events */}
      <section className="bg-neutral-50 border-y border-neutral-100">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-14 sm:py-20">
          <div className="flex items-end justify-between mb-8">
            <div>
              <Badge className="bg-secondary-50 text-secondary-700 mb-3">Events</Badge>
              <h2 className="text-2xl sm:text-3xl font-bold text-neutral-900 tracking-tight">Upcoming events</h2>
            </div>
            <Link to="/public/website/content/events" className="hidden sm:inline-flex items-center gap-1.5 text-sm font-semibold text-primary-600 hover:text-primary-700">
              View all events
              <ArrowRight size={16} />
            </Link>
          </div>

          {eventsLoading ? (
            <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
              <SkeletonCard /><SkeletonCard /><SkeletonCard />
            </div>
          ) : eventsError ? (
            <ErrorState message="Failed to load events. Please try again later." />
          ) : eventItems.length === 0 ? (
            <Card className="p-8 text-center text-sm text-neutral-500">No upcoming events scheduled.</Card>
          ) : (
            <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
              {eventItems.map((event: WebsiteContent) => {
                const eventDate = new Date(event.date);
                const day = eventDate.getDate();
                const month = eventDate.toLocaleDateString('en-KE', { month: 'short' });
                return (
                  <Card key={event.id} elevated className="p-5">
                    <div className="flex items-start gap-4">
                      <div className="flex-shrink-0 w-14 h-14 rounded-lg bg-primary-50 text-primary-700 flex flex-col items-center justify-center">
                        <span className="text-lg font-bold leading-none">{day}</span>
                        <span className="text-xs leading-none mt-0.5">{month}</span>
                      </div>
                      <div className="min-w-0 flex-1">
                        <h3 className="text-base font-semibold text-neutral-900 leading-snug line-clamp-2">{event.title}</h3>
                        <p className="mt-1.5 text-sm text-neutral-500 line-clamp-2">{event.content}</p>
                      </div>
                    </div>
                  </Card>
                );
              })}
            </div>
          )}
          <div className="mt-6 text-center sm:hidden">
            <Link to="/public/website/content/events">
              <Button variant="secondary">View all events</Button>
            </Link>
          </div>
        </div>
      </section>

      {/* Leadership Preview */}
      <section className="bg-white">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-14 sm:py-20">
          <div className="flex items-end justify-between mb-8">
            <div>
              <Badge className="bg-primary-50 text-primary-700 mb-3">Our Team</Badge>
              <h2 className="text-2xl sm:text-3xl font-bold text-neutral-900 tracking-tight">Meet the leadership</h2>
            </div>
            <Link to="/public/website/leadership" className="hidden sm:inline-flex items-center gap-1.5 text-sm font-semibold text-primary-600 hover:text-primary-700">
              View all leaders
              <ArrowRight size={16} />
            </Link>
          </div>

          {leadershipLoading ? (
            <LoadingState />
          ) : leadershipError ? (
            <ErrorState message="Failed to load leadership team." />
          ) : (
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6">
              {leaders.map((person: LeadershipPerson) => (
                <Link key={person.id} to={`/public/website/people/${person.slug}`}>
                  <Card elevated className="p-5 text-center h-full">
                    {person.photo ? (
                      <img src={person.photo} alt={person.name} className="w-16 h-16 sm:w-20 sm:h-20 rounded-full object-cover mx-auto" />
                    ) : (
                      <div className="w-16 h-16 sm:w-20 sm:h-20 rounded-full bg-primary-50 text-primary-700 font-semibold text-lg flex items-center justify-center mx-auto">
                        {initials(person.name)}
                      </div>
                    )}
                    <h3 className="mt-3 text-sm sm:text-base font-semibold text-neutral-900 leading-tight">{person.name}</h3>
                    <p className="mt-1 text-xs sm:text-sm text-primary-600 font-medium">{person.title}</p>
                    <p className="mt-2 text-xs text-neutral-500 line-clamp-2">{person.bio}</p>
                  </Card>
                </Link>
              ))}
            </div>
          )}
          <div className="mt-6 text-center sm:hidden">
            <Link to="/public/website/leadership">
              <Button variant="secondary">View all leaders</Button>
            </Link>
          </div>
        </div>
      </section>

      {/* CTA Section — saturated teal */}
      <section className="bg-primary-500">
        <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-14 sm:py-20 text-center">
          <Heart className="mx-auto text-primary-100" size={40} />
          <h2 className="mt-4 text-2xl sm:text-3xl lg:text-4xl font-bold text-white tracking-tight text-balance">
            Join our community of healthcare workers
          </h2>
          <p className="mt-3 text-base text-primary-50/80 max-w-2xl mx-auto">
            Become a member today and enjoy financial security, emergency support, and a network that cares for you as much as you care for others.
          </p>
          <div className="mt-8 flex flex-col sm:flex-row gap-3 justify-center">
            <Link to="/public/website/contact">
              <Button size="lg" className="bg-white text-primary-700 hover:bg-primary-50">
                Become a Member
                <ArrowRight size={18} />
              </Button>
            </Link>
            <Link to="/login">
              <Button size="lg" variant="secondary" className="border-white/20 bg-transparent text-white hover:bg-white/10">
                Member Login
              </Button>
            </Link>
          </div>
        </div>
      </section>
    </div>
  );
}
