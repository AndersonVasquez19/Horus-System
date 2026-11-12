import { HeroCarousel } from '@/components/home/hero-carousel';
import { ConveniosSection } from '@/components/home/convenios-section';
import { ScheduleLocation } from '@/components/layout/schedule-location';

export default function HomePage() {
  return (
    <>
      <HeroCarousel />
      <ConveniosSection />
      <ScheduleLocation />
    </>
  );
}
