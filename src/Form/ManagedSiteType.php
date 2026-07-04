<?php

namespace App\Form;

use App\Entity\ManagedSite;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class ManagedSiteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom du site',
                'constraints' => [
                    new NotBlank(['message' => 'Le nom du site est obligatoire.']),
                ],
                'attr' => [
                    'placeholder' => 'Ex: TUNIS_CENTRE_A',
                    'class' => 'site-input',
                ],
            ])
            ->add('maxTrafic', NumberType::class, [
                'label' => 'Max trafic (Mbps)',
                'required' => false,
                'html5' => true,
                'scale' => 4,
                'attr' => [
                    'placeholder' => 'Ex: 245.75',
                    'class' => 'site-input',
                ],
            ])
            ->add('maxTdd', NumberType::class, [
                'label' => 'Max TDD',
                'required' => false,
                'html5' => true,
                'scale' => 4,
                'attr' => [
                    'placeholder' => 'Ex: 120.50',
                    'class' => 'site-input',
                ],
            ])
            ->add('maxFdd', NumberType::class, [
                'label' => 'Max FDD',
                'required' => false,
                'html5' => true,
                'scale' => 4,
                'attr' => [
                    'placeholder' => 'Ex: 245.75',
                    'class' => 'site-input',
                ],
            ])
            ->add('capaciteTddMbps', NumberType::class, [
                'label' => 'Capacite TDD (Mbps)',
                'required' => false,
                'html5' => true,
                'scale' => 4,
                'attr' => [
                    'placeholder' => 'Ex: 500',
                    'class' => 'site-input',
                ],
            ])
            ->add('capaciteFddMbps', NumberType::class, [
                'label' => 'Capacite FDD (Mbps)',
                'required' => false,
                'html5' => true,
                'scale' => 4,
                'attr' => [
                    'placeholder' => 'Ex: 1000',
                    'class' => 'site-input',
                ],
            ])
            ->add('tauxUtilisation', NumberType::class, [
                'label' => 'Taux utilisation (%)',
                'required' => false,
                'html5' => true,
                'scale' => 4,
                'attr' => [
                    'placeholder' => 'Ex: 78.25',
                    'class' => 'site-input',
                ],
            ])
            ->add('typeTrans', TextType::class, [
                'label' => 'Type de liaison',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Ex: FO, FH, Backhaul Sharing',
                    'class' => 'site-input',
                ],
            ])
            ->add('classification', TextType::class, [
                'label' => 'Classification',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Ex: TF, FDD, NON_DEFINI',
                    'class' => 'site-input',
                ],
            ])
            ->add('nombreOccurrences', IntegerType::class, [
                'label' => 'Occurrences',
                'required' => false,
                'attr' => [
                    'min' => 0,
                    'placeholder' => 'Ex: 3',
                    'class' => 'site-input',
                ],
            ])
            ->add('nombreOccurrencesTdd', IntegerType::class, [
                'label' => 'Occurrence TDD',
                'required' => false,
                'attr' => [
                    'min' => 0,
                    'placeholder' => 'Ex: 1',
                    'class' => 'site-input',
                ],
            ])
            ->add('nombreOccurrencesFdd', IntegerType::class, [
                'label' => 'Occurrence FDD',
                'required' => false,
                'attr' => [
                    'min' => 0,
                    'placeholder' => 'Ex: 2',
                    'class' => 'site-input',
                ],
            ])
            ->add('decisionStatut', TextType::class, [
                'label' => 'Statut',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Ex: OK, Bridé, Congestionné',
                    'class' => 'site-input',
                ],
            ])
            ->add('etat', TextType::class, [
                'label' => 'Etat',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Ex: OK, BRIDE, CONGESTIONNE',
                    'class' => 'site-input',
                ],
            ])
            ->add('dateMax', DateTimeType::class, [
                'label' => 'Date max',
                'required' => false,
                'widget' => 'single_text',
                'html5' => true,
                'attr' => [
                    'class' => 'site-input',
                ],
            ])
            ->add('latitude', NumberType::class, [
                'label' => 'Latitude',
                'required' => false,
                'html5' => true,
                'scale' => 8,
                'attr' => [
                    'placeholder' => 'Ex: 36.8065',
                    'class' => 'site-input',
                ],
            ])
            ->add('longitude', NumberType::class, [
                'label' => 'Longitude',
                'required' => false,
                'html5' => true,
                'scale' => 8,
                'attr' => [
                    'placeholder' => 'Ex: 10.1815',
                    'class' => 'site-input',
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Notes',
                'required' => false,
                'attr' => [
                    'rows' => 5,
                    'placeholder' => 'Contexte, coordonnées, informations complémentaires...',
                    'class' => 'site-textarea',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ManagedSite::class,
        ]);
    }
}
